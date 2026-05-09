<?php
/**
 * Chaos harness for ext/async.
 *
 * Two-phase model:
 *   1. Build a scenario as entities (Coroutine, Channel) + ordered Actions.
 *   2. Either execute it (run()) or emit equivalent PHP source (emitCode()).
 *
 * Output is captured into a Recorder, NOT stdout — echo from inside spawned
 * coroutines does not pass through ob_start in the parent fiber. The Recorder
 * sidesteps that and gives us a clean per-test array of (sorted) lines.
 *
 * The model also predicts its own output multiset for order-independent
 * scenarios — anywhere the scenario does not depend on which coroutine the
 * scheduler picks first. For step 1 this means:
 *   - Print/Sleep/Suspend never depend on order (they are local).
 *   - 1-sender / 1-receiver Channel pairs are predictable (sender's value
 *     appears exactly once at the receiver).
 *   - Multi-sender / multi-receiver pairings, close, cancel, scope cancellation
 *     are NOT predictable as exact multisets — those scenarios use invariant
 *     checks instead (see Scenario::invariants()).
 */

namespace Async\Chaos;

use Async\Channel;
use function Async\spawn;
use function Async\await_all;
use function Async\suspend as async_suspend;
use function Async\delay as async_delay;

/* ---------- Output recording ---------- */

final class Recorder {
    /** @var string[] */
    public array $lines = [];

    public function emit(string $line): void {
        $this->lines[] = $line;
    }

    /** @return string[] sorted multiset */
    public function snapshot(): array {
        $copy = $this->lines;
        sort($copy);
        return $copy;
    }
}

/* ---------- Actions ---------- */

abstract class Action {
    abstract public function execute(Scenario $ctx): void;

    /** Lines this action is expected to produce in any successful run. */
    public function willPrint(): array {
        return [];
    }

    /** PHP fragment (for emitCode()). $ctxVar is the variable name holding $ctx. */
    abstract public function emit(string $ctxVar = '$ctx', string $indent = '    '): string;
}

final class PrintAction extends Action {
    public function __construct(public readonly string $line) {}

    public function execute(Scenario $ctx): void {
        $ctx->recorder->emit($this->line);
    }

    public function willPrint(): array {
        return [$this->line];
    }

    public function emit(string $ctxVar = '$ctx', string $indent = '    '): string {
        $esc = addcslashes($this->line, "\"\\\$");
        return $indent . $ctxVar . '->recorder->emit("' . $esc . '");';
    }
}

final class SuspendAction extends Action {
    public function execute(Scenario $ctx): void {
        async_suspend();
    }
    public function emit(string $ctxVar = '$ctx', string $indent = '    '): string {
        return $indent . '\\Async\\suspend();';
    }
}

final class SleepAction extends Action {
    public function __construct(public readonly int $ms) {}
    public function execute(Scenario $ctx): void {
        async_delay($this->ms);
    }
    public function emit(string $ctxVar = '$ctx', string $indent = '    '): string {
        return $indent . '\\Async\\delay(' . $this->ms . ');';
    }
}

final class SendAction extends Action {
    public function __construct(
        public readonly string $channelName,
        public readonly mixed $value,
    ) {}

    public function execute(Scenario $ctx): void {
        $ctx->channels[$this->channelName]->send($this->value);
        $ctx->recorder->emit("sent:{$this->channelName}:{$this->value}");
    }

    public function willPrint(): array {
        return ["sent:{$this->channelName}:{$this->value}"];
    }

    public function emit(string $ctxVar = '$ctx', string $indent = '    '): string {
        $v = var_export($this->value, true);
        return $indent . $ctxVar . '->channels["' . $this->channelName . '"]->send(' . $v . ');' . "\n"
             . $indent . $ctxVar . '->recorder->emit("sent:' . $this->channelName . ':' . $this->value . '");';
    }
}

final class RecvAction extends Action {
    public function __construct(public readonly string $channelName) {}

    public function execute(Scenario $ctx): void {
        $value = $ctx->channels[$this->channelName]->recv();
        $ctx->recorder->emit("recv:{$this->channelName}:{$value}");
    }

    /**
     * For a 1-sender / 1-receiver pair the predicted line is exactly the value
     * the sender will push. The scenario knows that pairing and fills predicted
     * lines via Scenario::predictedChannelOutput(); RecvAction itself can't
     * know what it will receive, so it abstains.
     */
    public function emit(string $ctxVar = '$ctx', string $indent = '    '): string {
        return $indent . '$_v = ' . $ctxVar . '->channels["' . $this->channelName . '"]->recv();' . "\n"
             . $indent . $ctxVar . '->recorder->emit("recv:' . $this->channelName . ':" . $_v);';
    }
}

/* ---------- Coroutine ---------- */

final class Coroutine {
    /** @var Action[] */
    public array $actions = [];

    public function __construct(public readonly int $id) {}

    public function add(Action $a): self {
        $this->actions[] = $a;
        return $this;
    }

    public function run(Scenario $ctx): void {
        foreach ($this->actions as $a) {
            $a->execute($ctx);
        }
    }

    public function willPrint(): array {
        $lines = [];
        foreach ($this->actions as $a) {
            foreach ($a->willPrint() as $l) {
                $lines[] = $l;
            }
        }
        return $lines;
    }

    public function emit(string $ctxVar = '$ctx'): string {
        $body = '';
        foreach ($this->actions as $a) {
            $body .= $a->emit($ctxVar) . "\n";
        }
        return "spawn(function() use ({$ctxVar}) {\n" . $body . "});\n";
    }
}

/* ---------- Channel description ---------- */

final class ChannelDef {
    public function __construct(
        public readonly string $name,
        public readonly int $capacity,
    ) {}
}

/* ---------- Scenario ---------- */

final class Scenario {
    /** @var Coroutine[] */
    public array $coroutines = [];
    /** @var array<string, ChannelDef> */
    private array $channelDefs = [];
    /** @var array<string, Channel> populated during run() */
    public array $channels = [];

    public Recorder $recorder;

    public function __construct() {
        $this->recorder = new Recorder();
    }

    public function addChannel(string $name, int $capacity): self {
        $this->channelDefs[$name] = new ChannelDef($name, $capacity);
        return $this;
    }

    public function addCoroutine(Coroutine $c): self {
        $this->coroutines[] = $c;
        return $this;
    }

    /**
     * Predicted output multiset.
     * For step 1 we trust each Action::willPrint() — Recv lines come from the
     * matching Send (caller is responsible for ensuring 1:1 send/recv pairing
     * for predictability; multi-pairings should use invariants instead).
     */
    public function predictedOutput(): array {
        $lines = [];
        foreach ($this->coroutines as $c) {
            foreach ($c->willPrint() as $l) {
                $lines[] = $l;
            }
        }
        // For each Send/Recv pair on the same channel, recv produces the value.
        // We add one "recv:CH:VAL" per send line we already counted.
        $extra = [];
        foreach ($lines as $l) {
            if (str_starts_with($l, 'sent:')) {
                [, $ch, $val] = explode(':', $l, 3);
                $extra[] = "recv:{$ch}:{$val}";
            }
        }
        $all = array_merge($lines, $extra);
        sort($all);
        return $all;
    }

    public function run(): array {
        $this->recorder = new Recorder();
        $this->channels = [];
        foreach ($this->channelDefs as $name => $def) {
            $this->channels[$name] = new Channel($def->capacity);
        }

        $self = $this;
        $handles = [];
        foreach ($this->coroutines as $c) {
            $coro = $c;
            $handles[] = spawn(function() use ($coro, $self) {
                $coro->run($self);
            });
        }
        await_all($handles);

        foreach ($this->channels as $ch) {
            if (!$ch->isClosed()) {
                $ch->close();
            }
        }

        return $this->recorder->snapshot();
    }

    public function emitCode(): string {
        $code = "<?php\nuse function Async\\spawn;\nuse function Async\\await_all;\nuse Async\\Channel;\n\n";
        $code .= "\$ctx = new \\stdClass();\n";
        $code .= "\$ctx->recorder = new \\Async\\Chaos\\Recorder();\n";
        $code .= "\$ctx->channels = [];\n";
        foreach ($this->channelDefs as $def) {
            $code .= "\$ctx->channels['{$def->name}'] = new Channel({$def->capacity});\n";
        }
        $code .= "\$coros = [];\n";
        foreach ($this->coroutines as $c) {
            $code .= "\$coros[] = " . $c->emit('$ctx');
        }
        $code .= "await_all(\$coros);\n";
        return $code;
    }
}

/* ---------- Generator ---------- */

final class Generator {
    private int $rng;

    public function __construct(int $seed) {
        $this->rng = $seed === 0 ? 0xC0FFEE : $seed;
    }

    private function next(int $min, int $max): int {
        $x = $this->rng & 0xFFFFFFFF;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5)  & 0xFFFFFFFF;
        $this->rng = $x;
        return $min + ($x % ($max - $min + 1));
    }

    /** Step-1 recipe: independent print-only coroutines. Always order-independent. */
    public function printOnly(int $minCoros = 2, int $maxCoros = 6, int $maxStmts = 4): Scenario {
        $s = new Scenario();
        $n = $this->next($minCoros, $maxCoros);
        for ($i = 0; $i < $n; $i++) {
            $c = new Coroutine($i);
            $stmts = $this->next(1, $maxStmts);
            for ($j = 0; $j < $stmts; $j++) {
                $c->add(new PrintAction("c{$i}/s{$j}"));
                if ($this->next(0, 3) === 0) {
                    $c->add(new SuspendAction());
                }
            }
            $s->addCoroutine($c);
        }
        return $s;
    }

    /** Step-2 recipe: one channel, one sender, one receiver. Output predictable. */
    public function singlePairChannel(int $bufferedCapacity = 0, int $messages = 5): Scenario {
        $s = new Scenario();
        $s->addChannel('ch', $bufferedCapacity);

        $sender = new Coroutine(0);
        for ($i = 0; $i < $messages; $i++) {
            $sender->add(new SendAction('ch', $i));
            if ($this->next(0, 2) === 0) {
                $sender->add(new SuspendAction());
            }
        }

        $receiver = new Coroutine(1);
        for ($i = 0; $i < $messages; $i++) {
            $receiver->add(new RecvAction('ch'));
        }

        $s->addCoroutine($sender);
        $s->addCoroutine($receiver);
        return $s;
    }
}
