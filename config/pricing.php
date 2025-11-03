<?php
// Pricing table for model token costs (USD per 1K tokens)
// Extend this as needed for more models or updated pricing.
return [
    // Model => [ 'prompt' => price per 1K prompt tokens, 'completion' => price per 1K completion tokens ]
    'gpt-4.1' => [
        'prompt' => 0.03,      // $0.03 per 1K prompt tokens
        'completion' => 0.06   // $0.06 per 1K completion tokens
    ],
    'gpt-3.5-turbo' => [
        'prompt' => 0.001,
        'completion' => 0.002
    ],
    'gpt-5' => [
        'prompt' => 0.10,      // $0.10 per 1K prompt tokens (example)
        'completion' => 0.20   // $0.20 per 1K completion tokens (example)
    ],
    // Add more models as needed
];
