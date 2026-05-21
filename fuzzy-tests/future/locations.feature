Feature: Future / FutureState created & completed locations under chaos

  Future and FutureState each expose four debug-location accessors:

    getCreatedFileAndLine() / getCreatedLocation()
      — where the FutureState was constructed; fixed for the whole lifetime
    getCompletedFileAndLine() / getCompletedLocation()
      — where complete() / error() was called; meaningful once settled

  An inspector coroutine races the producer. getCreated* is well-formed at
  every instant; getCompleted* is at least well-typed at every instant.
  After run() the future has settled, so every accessor on both the Future
  and its FutureState is a well-formed [file,int] pair / "file:line" string.

  Invariants for every interleaving:
    fut_loc_ok_F == fut_loc_attempts_F
    fut_loc_bad_F == 0

  Scenario: inspect locations of a future that completes
    Given a future "F"
      And a coroutine "P"
      And a coroutine "I"
      And a coroutine "A"
     When coroutine "P" completes future "F" with 42
      And coroutine "I" inspects locations of future "F"
      And coroutine "A" awaits future "F"
     Then counter "fut_loc_attempts_F" equals 1
      And counter "fut_loc_ok_F" equals 1
      And counter "fut_loc_bad_F" equals 0
      And future "F" has well-formed created and completed locations
      And no orphan coroutines

  Scenario: inspect locations of a future that errors
    Given a future "F"
      And a coroutine "P"
      And a coroutine "I"
      And a coroutine "A"
     When coroutine "P" fails future "F" with "boom"
      And coroutine "I" inspects locations of future "F"
      And coroutine "A" awaits future "F"
     Then counter "fut_loc_attempts_F" equals 1
      And counter "fut_loc_ok_F" equals 1
      And counter "fut_loc_bad_F" equals 0
      And future "F" has well-formed created and completed locations
      And no orphan coroutines

  Scenario: many inspectors race the producer
    Given a future "F"
      And a coroutine "P"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
      And a coroutine "I4"
     When coroutine "P" completes future "F" with 7
      And coroutine "I1" inspects locations of future "F"
      And coroutine "I2" inspects locations of future "F"
      And coroutine "I3" inspects locations of future "F"
      And coroutine "I4" inspects locations of future "F"
     Then counter "fut_loc_attempts_F" equals 4
      And counter "fut_loc_ok_F" equals 4
      And counter "fut_loc_bad_F" equals 0
      And future "F" has well-formed created and completed locations
      And no orphan coroutines

  Scenario Outline: created location stays well-formed across payloads
    Given a future "F"
      And a coroutine "P"
      And a coroutine "I"
     When coroutine "P" completes future "F" with <val>
      And coroutine "I" inspects locations of future "F"
     Then counter "fut_loc_ok_F" equals 1
      And counter "fut_loc_bad_F" equals 0
      And future "F" has well-formed created and completed locations
      And no orphan coroutines

    Examples:
      | val |
      | 0   |
      | 1   |
      | 100 |
