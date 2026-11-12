<?php

declare(strict_types=1);

namespace App\Service\Automation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An AI-generated illustration for a post banner (OpenAI Images). The banner generator draws the title over it.
 * Without OPENAI_API_KEY, or when the call fails, it returns null and the banner falls back to generative art.
 */
final class AiBannerArt
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $openAiApiKey,
        private readonly string $bannerImageModel,
    ) {}

    public function isEnabled(): bool
    {
        return trim($this->openAiApiKey) !== '';
    }

    public function create(string $title, string $kicker): ?\GdImage
    {
        if (!$this->isEnabled()) {
            return null;
        }
        try {
            $response = $this->http->request('POST', 'https://api.openai.com/v1/images/generations', [
                'timeout' => 90,
                'max_duration' => 150,
                'auth_bearer' => $this->openAiApiKey,
                'json' => [
                    'model' => $this->bannerImageModel,
                    'prompt' => self::prompt($title, $kicker),
                    'size' => '1536x1024',
                    'quality' => 'medium',
                    'n' => 1,
                ],
            ])->toArray();
            $png = base64_decode((string) ($response['data'][0]['b64_json'] ?? ''), true);
            $image = $png ? imagecreatefromstring($png) : false;

            return $image instanceof \GdImage ? $image : null;
        } catch (\Throwable $e) {
            $this->logger->warning('AI banner art failed; using generative art instead', ['exception' => $e]);

            return null;
        }
    }

    public static function prompt(string $title, string $kicker): string
    {
        return implode(' ', [
            'Editorial header illustration for a software engineering blog post.',
            sprintf('Post title: "%s". Topic: %s.', str_replace('"', '', mb_substr($title, 0, 140)), mb_substr($kicker, 0, 48)),
            'Show a visual metaphor for the subject, not a literal screenshot. Modern, cinematic lighting, rich colour, depth.',
            'Keep the left half calm and darker so a white headline can sit on it.',
            'Absolutely no text, letters, numbers, logos, user interfaces or watermarks.',
        ]);
    }
}
