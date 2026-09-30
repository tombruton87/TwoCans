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
