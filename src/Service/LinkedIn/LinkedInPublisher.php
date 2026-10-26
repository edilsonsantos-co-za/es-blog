<?php

declare(strict_types=1);

namespace App\Service\LinkedIn;

use App\Entity\CustomField;
use App\Entity\LinkedInIntegration;
use App\Entity\Post;
use App\Enum\PostStatus;
use App\Repository\LinkedInIntegrationRepository;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shares a published post to the connected LinkedIn profile, once per post.
 */
final class LinkedInPublisher
{
    /** Custom field holding the LinkedIn text written for a post; falls back to the description. */
    public const COMMENTARY_FIELD = 'linkedin_text';

    public function __construct(
        private readonly LinkedInClient $client,
        private readonly LinkedInIntegrationRepository $integrations,
        private readonly TokenCipher $cipher,
        private readonly SiteConfigService $config,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {}

    public function connection(): ?LinkedInIntegration
    {
        return $this->integrations->findOneBy([], ['updatedAt' => 'DESC']);
    }

    public function accessToken(LinkedInIntegration $integration): ?string
    {
        $stored = $integration->getAccessToken();
        if ($stored === null || $integration->isTokenExpired()) {
            return null;
        }

        return $this->cipher->decrypt($stored);
    }

    /**
     * @return string|null the LinkedIn post URN, or null when there was nothing to do
     */
    public function share(Post $post, bool $force = false): ?string
    {
        if ($post->getStatus() !== PostStatus::Published || ($post->getLinkedInPostUrn() !== null && !$force)) {
            return null;
        }
        if (!$force && !$this->config->getBool('linkedin.auto_share', true)) {
            return null;
        }

        $integration = $this->connection() ?? throw new \RuntimeException('LinkedIn is not connected.');
        $token = $this->accessToken($integration)
            ?? throw new \RuntimeException('The LinkedIn token has expired. Reconnect LinkedIn in the admin.');
        $author = $integration->getUserUrn() ?? throw new \RuntimeException('LinkedIn member id is missing. Reconnect LinkedIn.');

        $imageUrn = null;
        $image = $post->getImage();
        if ($image !== null && str_starts_with($image, '/uploads/')) {
            $path = $this->projectDir . '/public' . $image;
            if (is_file($path)) {
                $imageUrn = $this->client->uploadImage($token, $author, $path);
            }
        }

        $urn = $this->client->sharePost(
            $token,
            $author,
            $this->commentary($post),
            $this->postUrl($post),
            $post->getTitle(),
            (string) $post->getDescription(),
            $imageUrn,
        );
        $post->markSharedOnLinkedIn($urn);
        $this->em->flush();

        return $urn;
    }

    public function commentary(Post $post): string
    {
        foreach ($post->getCustomFields() as $field) {
            if ($field->getFieldKey() === self::COMMENTARY_FIELD && trim((string) $field->getFieldValue()) !== '') {
                return trim((string) $field->getFieldValue());
            }
        }

        return trim($post->getTitle() . "\n\n" . (string) $post->getDescription());
    }

    public static function commentaryField(Post $post, string $text): CustomField
    {
        return (new CustomField())->setPost($post)->setFieldKey(self::COMMENTARY_FIELD)->setFieldValue($text);
    }

    public function postUrl(Post $post): string
    {
        $date = $post->getPublishedAt() ?? $post->getCreatedAt();
        $params = ['year' => $date->format('Y'), 'month' => $date->format('m'), 'slug' => $post->getSlug()];
        $base = rtrim((string) $this->config->get('site.url', ''), '/');

        return $base !== ''
            ? $base . $this->urls->generate('app_blog_post', $params)
            : $this->urls->generate('app_blog_post', $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
