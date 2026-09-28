<?php

return [
    'plans' => [
        'basic' => [
            'subjects' => ['math'],
            'max_exercises_per_subject' => 5,
        ],
        'family' => [
            'subjects' => ['math', 'english'],
            'max_exercises_per_subject' => 10,
        ],
        'premium' => [
            'subjects' => ['math', 'english', 'geo'],
            'max_exercises_per_subject' => 10,
        ],
    ],
];
