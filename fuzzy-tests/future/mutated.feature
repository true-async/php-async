Feature: Future scenarios assembled from mutation blocks

  Demonstrates mutation blocks — a step slot the generator expands into
  several concrete variants:

    One of:   exactly one alternative is chosen per generated .phpt
    Any of:   any subset of alternatives is chosen per generated .phpt

  Multiple blocks multiply: the scenario below has 3 x 2 = 6 variants, one
  .phpt each. The Then-invariants must hold for EVERY variant — so they are
  written against counters that are valid whichever alternatives ran.

  Scenario: a future is created one way, then one thing happens to it
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A"
    One of:
      - coroutine "P" completes future "F" with 1
      - coroutine "P" completes future "F" with 2
      - coroutine "P" fails future "F" with "boom"
    One of:
      - coroutine "A" awaits future "F"
      - coroutine "A" inspects locations of future "F"
     Then counter "fut_loc_bad_F" equals 0
      And no orphan coroutines

  Scenario: optional observers — any subset may watch the future
    Given a future "F"
      And a coroutine "P"
      And a coroutine "O1"
      And a coroutine "O2"
     When coroutine "P" completes future "F" with 42
    Any of:
      - coroutine "O1" awaits future "F"
      - coroutine "O2" inspects locations of future "F"
     Then counter "fut_loc_bad_F" equals 0
      And no orphan coroutines

  Scenario Outline: mutation blocks combine with Examples rows
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A"
    One of:
      - coroutine "P" completes future "F" with <val>
      - coroutine "P" fails future "F" with "err"
     When coroutine "A" inspects locations of future "F"
     Then counter "fut_loc_attempts_F" equals 1
      And counter "fut_loc_bad_F" equals 0
      And no orphan coroutines

    Examples:
      | val |
      | 7   |
      | 99  |
