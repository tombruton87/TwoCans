<?php
declare(strict_types=1);

/**
 * The games line: one number (4263, G-A-M-E, by default) with a menu, and
 * the games on it — all played on the keypad, in the quiz's voice (see
 * storage/defaults/README.md). The dialplan is PjsipConfig::renderGames();
 * how everyone got on is Quiz.
 */
final class Games
{
    /** Each game: what it's called, and the key that picks it from the menu. */
    public const GAMES = [
        'times' => ['label' => 'Times tables', 'key' => '1'],
        'sums' => ['label' => 'Sums', 'key' => '2'],
        'bonds' => ['label' => 'Number bonds', 'key' => '3'],
        'guess' => ['label' => 'Guess my number', 'key' => '4'],
        'riddles' => ['label' => 'Animal riddles', 'key' => '5'],
    ];

    /** Riddles a game of animal riddles asks. */
    public const RIDDLES_A_GAME = 5;

    /**
     * The animal riddles, as recorded in storage/defaults/quiz/riddle-<n>.wav,
     * with the key that answers each.
     *
     * @var array<int,array{text:string,answer:int}>
     */
    public const RIDDLES = [
        1 => ['text' => 'I have a very long trunk and big flappy ears. Am I: one, a lion. Two, an elephant. Or three, a monkey?', 'answer' => 2],
        2 => ['text' => 'I say moo, and I give you milk. Am I: one, a cow. Two, a duck. Or three, a pig?', 'answer' => 1],
        3 => ['text' => 'I hop about, and I carry my baby in a pouch. Am I: one, a frog. Two, a rabbit. Or three, a kangaroo?', 'answer' => 3],
        4 => ['text' => 'I have a long neck, so I can eat leaves from the tallest trees. Am I: one, a giraffe. Two, a zebra. Or three, a bear?', 'answer' => 1],
        5 => ['text' => 'I have black and white stripes, and I look like a horse. Am I: one, a tiger. Two, a zebra. Or three, a panda?', 'answer' => 2],
        6 => ['text' => 'I say quack, and I love to swim in the pond. Am I: one, a duck. Two, a cat. Or three, a sheep?', 'answer' => 1],
        7 => ['text' => "I'm the king of the jungle, and I have a big fluffy mane. Am I: one, a mouse. Two, a dog. Or three, a lion?", 'answer' => 3],
        8 => ['text' => 'I have eight long legs, and I spin a web. Am I: one, a spider. Two, a snail. Or three, a bee?', 'answer' => 1],
        9 => ['text' => 'I carry my house on my back, and I move very slowly. Am I: one, a horse. Two, a snail. Or three, a fish?', 'answer' => 2],
        10 => ['text' => "I'm black and white, and I love to munch bamboo. Am I: one, a penguin. Two, a skunk. Or three, a panda?", 'answer' => 3],
        11 => ['text' => "I can't fly, but I'm brilliant at swimming in icy water. Am I: one, a penguin. Two, a parrot. Or three, an owl?", 'answer' => 1],
        12 => ['text' => 'I say baa, and my woolly coat keeps you warm. Am I: one, a goat. Two, a sheep. Or three, a cow?', 'answer' => 2],
        13 => ['text' => "I'm awake at night, I say twit twoo, and I can turn my head right round. Am I: one, a bat. Two, a robin. Or three, an owl?", 'answer' => 3],
        14 => ['text' => 'I have a long tail, I love bananas, and I swing through the trees. Am I: one, a monkey. Two, a crocodile. Or three, an elephant?', 'answer' => 1],
        15 => ['text' => 'I have lots of sharp teeth, and I snap my jaws in the river. Am I: one, a hippo. Two, a crocodile. Or three, a duck?', 'answer' => 2],
    ];

    public static function label(string $game): string
    {
        return self::GAMES[$game]['label'] ?? 'A game';
    }
}
