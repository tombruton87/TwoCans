# Built-in sounds

Sounds twocans ships with, for when a household hasn't recorded its own. They
are committed to the repository (unlike the rest of `storage/`, which is the
household's own and never is), and Asterisk reads them from here as
`/var/lib/twocans/defaults/`.

Each is already in the format Asterisk plays: WAV, 8 kHz, mono, 16-bit,
loudness evened out (as `AudioStore` converts an upload).

| File | Plays | Says |
|---|---|---|
| `test-call.wav` | A test call (the phone page's Test call, or dialling 601), when the household hasn't recorded a greeting | "Congratulations! Your new phone is now on the twocans system. You can start using it now. Try calling a number your parents didn't approve. We dare you — you can't." |

`test-call.wav` was generated with ElevenLabs text-to-speech, voice "Rory
Talks – British Conversational, Real & Casual" (from ElevenLabs' Voice
Library).

## The games — `quiz/`

The voice of the games line (4263) and the times tables quiz (246), or
whatever numbers the household picks,
generated with Higgsfield's text-to-speech (Seed Audio, preset voice "Isla")
and converted as above, with the silence trimmed from each end so the pieces
run together.

| Files | Say |
|---|---|
| `welcome.wav` | "Hello! Welcome to the times tables quiz." |
| `pick.wav` | "Which times table would you like? Type a number from two to twelve, then press the hash key. Or just press hash for a mix." |
| `how.wav` | "Type your answer on the keypad, then press the hash key. Here we go!" |
| `whats.wav`, `times.wav` | "What is" … "times" — a question is these with two numbers |
| `nudge.wav` | "Have a go! Type your answer, then press the hash key." |
| `right-1.wav` … `right-4.wav` | "Well done!", "That's right!", "Brilliant!", "Correct! Great job!" |
| `wrong.wav` | "Not quite. The answer is" |
| `you-got.wav`, `right-out-of.wav` | "You got" … "right, out of" — the score: "You got 8 right, out of 10" |
| `perfect.wav`, `good.wav` | "That's a perfect score! Amazing!", "Great effort! Keep practising." |
| `bye.wav` | "Thanks for playing. Goodbye!" |
| `n-0.wav` … `n-20.wav`, `n-30.wav` … `n-90.wav`, `n-100.wav`, `n-100-and.wav` | The numbers: "zero" to "twenty", "thirty" to "ninety", "one hundred", "one hundred and" — see `PjsipConfig::quizSay()` |
| `games-menu.wav` | "Welcome to the games line! Press 1 for times tables. 2 for sums. 3 for number bonds. 4 for guess my number. Or 5 for animal riddles." |
| `sums-welcome.wav`, `plus.wav`, `take-away.wav` | "Let's do some sums! …", "plus", "take away" |
| `bonds-welcome.wav`, `bonds-what-goes.wav`, `bonds-to-make.wav` | "Let's find number bonds! …", "What goes with" … "to make" |
| `guess-welcome.wav`, `higher.wav`, `lower.wav` | "I'm thinking of a number between one and a hundred …", "Higher than that!", "Lower than that!" |
| `guess-got-it.wav`, `guess-goes.wav`, `guess-first.wav` | "You got it! It took you" … "goes. Brilliant guessing!", "Wow! You got it first go!" |
| `riddles-welcome.wav`, `riddle-wrong.wav`, `riddle-nudge.wav` | "Let's play animal riddles! …", "Oh no, not that one! The answer was number", "Press 1, 2 or 3." |
| `riddle-1.wav` … `riddle-15.wav` | The riddles, word for word as in `Games::RIDDLES` |
| `radio-welcome.wav`, `radio-all.wav` | The radio's menu: "Welcome to twocans radio! Which station would you like?" … "Or press zero for all the songs." |
| `press-1.wav` … `press-5.wav`, `station-1.wav` … `station-5.wav` | "Press one for" … and, for a station nobody's named, "station one." |
| `radio-bedtime.wav`, `radio-goodnight.wav` | "It's bedtime, so the radio's having a little sleep. Night night!", "That's all the songs for tonight. Night night, sleep tight!" (the sleep timer) |
| `timer-ask.wav`, `timer-set.wav`, `timer-minutes.wav`, `timer-set-one.wav` | The kitchen timer: "Kitchen timer! How many minutes? …", "Okay! I'll ring you in" … "minutes. Bye for now!", "… one minute. Bye for now!" |
| `timer-done.wav`, `timer-cancelled.wav` | "Ding ding! Your timer's finished!", "Okay, I've cancelled your timer. Bye for now!" |
| `clock-its.wav`, `clock-oclock.wav`, `clock-past.wav`, `clock-to.wav` | "What time is it?": "It's" … "o'clock.", "past", "minutes to" |
| `clock-quarter-past.wav`, `clock-half-past.wav`, `clock-quarter-to.wav` | "It's quarter past", "It's half past", "It's quarter to" |
| `bedtime-in.wav`, `bedtime-in-an-hour-and.wav`, `bedtime-in-an-hour.wav`, `bedtime-minutes.wav`, `bedtime-now.wav` | "Bedtime is in" (cut from the next one, the take that says its B) … "minutes!", "Bedtime is in an hour and", "Bedtime is in an hour.", "It's bedtime! Night night, sleep tight!" |
| `silly-say.wav`, `silly-chipmunk.wav`, `silly-giant.wav`, `silly-again.wav` | Silly voices: "Say something silly after the beep. Then press the hash key.", "Here's you as a chipmunk!", "And here's you as a giant!", "Press 1 to have another go, or hang up to finish." |

## Sleeps till Christmas — `christmas/`

Santa, counting down the sleeps (dial 1225, or whatever number the household
picks), and his Christmas Day message — generated with Higgsfield's
text-to-speech (Seed Audio, preset voice "Sterling") and converted as above.
A household can record its own Christmas Day message instead; this one plays
without one.

| Files | Say |
|---|---|
| `intro.wav`, `sleeps-until.wav` | "Ho ho ho! Hello there, it's Santa! There are" … "sleeps until Christmas!" |
| `signoff-1.wav` … `signoff-4.wav` | "The reindeer are practising their flying!", "The elves are busy wrapping presents!", "I'm checking my list, and checking it twice!", "Be good, and I'll see you very soon. Ho ho ho!" |
| `christmas-eve.wav` | "Ho ho ho! It's Christmas Eve! Just one more sleep until Christmas! …" |
| `christmas-day.wav` | "Ho ho ho! Merry Christmas! It's Santa here, calling all the way from the North Pole. …" |
| `n-2.wav` … `n-20.wav`, `n-30.wav` … `n-90.wav`, `n-100.wav` … `n-300-and.wav` | The numbers, 2 to 365 — see `PjsipConfig::quizSay()` |

