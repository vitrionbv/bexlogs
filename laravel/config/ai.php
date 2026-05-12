<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenRouter API
    |--------------------------------------------------------------------------
    |
    | OpenRouter exposes the OpenAI /v1/chat/completions schema (incl. tool
    | calls + streaming). We hit it through a tiny Http facade wrapper so we
    | can swap providers later by editing this single file.
    |
    | The HTTP-Referer / X-Title headers are OpenRouter's free-tier-friendly
    | request labels — they don't affect billing but show up in their public
    | leaderboard if app_url / app_title are set.
    */
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'api_key' => env('OPENROUTER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Model + budget
    |--------------------------------------------------------------------------
    */
    'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'openai/gpt-5-mini'),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1024),
    'tool_iteration_cap' => (int) env('AI_TOOL_ITERATION_CAP', 6),
    'request_timeout_seconds' => (int) env('AI_REQUEST_TIMEOUT_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Per-user safety cap
    |--------------------------------------------------------------------------
    |
    | Hard ceiling on (input_tokens + output_tokens) summed across every
    | ai_messages row a user produced today. Once exceeded the chat endpoint
    | returns 429 BEFORE calling OpenRouter so a runaway tool loop can't burn
    | the whole budget.
    */
    'daily_token_cap_per_user' => (int) env('AI_DAILY_TOKEN_CAP_PER_USER', 200_000),

    /*
    |--------------------------------------------------------------------------
    | Conversation memory
    |--------------------------------------------------------------------------
    |
    | Cap how many of a conversation's prior messages get replayed to the
    | provider on each turn. Keeps long sessions from quietly inflating
    | context cost; the system prompt and the in-flight user message are
    | always sent in addition to this slice.
    */
    'context_message_cap' => (int) env('AI_CONTEXT_MESSAGE_CAP', 24),

    /*
    |--------------------------------------------------------------------------
    | OpenRouter request labels (optional)
    |--------------------------------------------------------------------------
    */
    'app_url' => env('OPENROUTER_APP_URL', env('APP_URL')),
    'app_title' => env('OPENROUTER_APP_TITLE', env('APP_NAME', 'Bexlogs')),
];
