<?php

declare(strict_types=1);

namespace App\Service\LinkedIn;

use App\Entity\CustomField;
use App\Entity\LinkedInIntegration;
use App\Entity\Post;
use App\Entity\User;
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
    /** Placeholder URNs: claimed before calling LinkedIn / outcome unknown after an ambiguous failure. */
    public const PENDING = 'pending';
    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly LinkedInClient $client,
        private readonly LinkedInIntegrationRepository $integrations,
        private readonly TokenCipher $cipher,
        private readonly SiteConfigService $config,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {}

    public function connection(?User $author = null): ?LinkedInIntegration
    {
        return $author === null
            ? $this->integrations->findOneBy([], ['updatedAt' => 'DESC'])
            : $this->integrations->findForUser($author);
    }

    public function accessToken(LinkedInIntegration $integration): ?string
    {
        $stored = $integration->getAccessToken();
        if ($stored === null || $integration->isTokenExpired()) {
            return null;
        }

        return $this->cipher->decrypt($stored);
    }

    /** Hash of everything the share sends; the review button snapshots it into the queued message. */
    public function reviewHash(Post $post): string
    {
        return hash('sha256', json_encode([
            $this->commentary($post),
            $this->postUrl($post),
            $post->getTitle(),
            (string) $post->getDescription(),
            $post->getImage(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Claims posts.linkedin_post_urn atomically before any remote call so concurrent or retried
     * handlers cannot both post. Only explicit reviewed shares exist; a changed post is refused.
     *
     * @return string|null the LinkedIn post URN, or null when there was nothing to do
     */
    public function share(Post $post, string $reviewedHash): ?string
    {
        if (!$post->isPublished() || $post->getLinkedInPostUrn() !== null) {
            return null;
        }
        if (!hash_equals($this->reviewHash($post), $reviewedHash)) {
            return null;
        }

        $integration = $this->connection($post->getAuthor()) ?? throw new \RuntimeException('This post author has not connected LinkedIn.');
        $token = $this->accessToken($integration)
            ?? throw new \RuntimeException('The LinkedIn token has expired. Reconnect LinkedIn in the admin.');
        $author = $integration->getUserUrn() ?? throw new \RuntimeException('LinkedIn member id is missing. Reconnect LinkedIn.');

        $conn = $this->em->getConnection();
        $claimed = $conn->executeStatement(
            'UPDATE posts SET linkedin_post_urn = ? WHERE id = ? AND linkedin_post_urn IS NULL',
            [self::PENDING, $post->getId()],
        );
        if ($claimed !== 1) {
            return null;
        }

        $sending = false;
        try {
            $imageUrn = null;
            $image = $post->getImage();
            if ($image !== null && str_starts_with($image, '/uploads/')) {
                $path = $this->projectDir . '/public' . $image;
                if (is_file($path)) {
                    $imageUrn = $this->client->uploadImage($token, $author, $path);
                }
            }

            $sending = true;
            $urn = $this->client->sharePost(
                $token,
                $author,
                $this->commentary($post),
                $this->postUrl($post),
                $post->getTitle(),
                (string) $post->getDescription(),
                $imageUrn,
            );
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $conn->executeStatement(
                'UPDATE posts SET linkedin_post_urn = ? WHERE id = ? AND linkedin_post_urn = ?',
                [!$sending || ($code >= 400 && $code < 500) ? null : self::UNKNOWN, $post->getId(), self::PENDING],
            );

            throw $e;
        }

        try {
            $post->markSharedOnLinkedIn($urn);
            $this->em->flush();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('LinkedIn post %s was created but not recorded for post %d; set posts.linkedin_post_urn manually.', $urn, $post->getId()), 0, $e);
        }

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
