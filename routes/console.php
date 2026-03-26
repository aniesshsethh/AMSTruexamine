<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('openai:models {--filter=} {--raw}', function () {
    $apiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY'));

    if ($apiKey === '') {
        $this->error('OPENAI_API_KEY is not configured.');

        return self::FAILURE;
    }

    $response = Http::withToken($apiKey)
        ->acceptJson()
        ->timeout(20)
        ->get('https://api.openai.com/v1/models');

    if ($response->failed()) {
        $this->error('Failed to fetch models from OpenAI.');
        $this->line('Status: '.$response->status());
        $this->line('Body: '.$response->body());

        return self::FAILURE;
    }

    $models = collect($response->json('data', []))
        ->pluck('id')
        ->filter(fn ($id): bool => is_string($id) && $id !== '')
        ->sort()
        ->values();

    $filter = (string) $this->option('filter');

    if ($filter !== '') {
        $needle = strtolower($filter);
        $models = $models->filter(fn (string $id): bool => str_contains(strtolower($id), $needle))
            ->values();
    }

    if ((bool) $this->option('raw')) {
        $this->line($models->implode(PHP_EOL));

        return self::SUCCESS;
    }

    if ($models->isEmpty()) {
        $this->warn('No models matched your filter.');

        return self::SUCCESS;
    }

    $this->info('Available OpenAI models:');
    $this->table(['Model ID'], $models->map(fn (string $id): array => [$id])->all());

    return self::SUCCESS;
})->purpose('List OpenAI models available to this API key');
