<?php

declare(strict_types=1);

namespace App\Service\Automation;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * Shape Claude returns for an original multi-source editorial draft.
 */
final class DraftedPost implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Post title, under 90 characters, no clickbait')]
    public string $title;

    #[Constrained(description: 'Meta description for search results, under 160 characters')]
    public string $description;

    #[Constrained(description: 'Two or three word topic label for the banner, e.g. "Symfony release"')]
    public string $kicker;

    #[Constrained(description: 'Original synthesis in Markdown, 450 to 800 words, no H1. Compare the supplied sources, explain implications, distinguish facts from interpretation. No copied paragraphs or invented facts. Source references are appended by the application.')]
    public string $body;

    #[Constrained(description: 'Three to five lowercase tags, comma separated')]
    public string $tags;

    #[Constrained(description: 'LinkedIn post text, 80 to 160 words, plain text, at most two emojis, ending with three relevant hashtags. Do not include the URL; it is attached as a link card.')]
    public string $linkedin;

    #[Constrained(description: 'An original X/Twitter micro-post summarising the same insight, plain text, at most 250 characters. No URL, it is appended when sharing. No invented claims.')]
    public string $twitter;
}
