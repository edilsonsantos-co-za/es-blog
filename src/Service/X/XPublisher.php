<?php

declare(strict_types=1);

namespace App\Service\X;

use App\Entity\CustomField;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\XIntegration;
use App\Entity\XPostShare;
use App\Repository\XIntegrationRepository;
use App\Repository\XPostShareRepository;
use App\Service\LinkedIn\TokenCipher;
use App\Service\SiteConfigService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class XPublisher
{
    public const TEXT_FIELD = 'twitter_text';
    public const POST_ID_FIELD = 'twitter_post_id';

    public function __construct(
        private readonly XClient $client,
        private readonly XIntegrationRepository $integrations,
        private readonly XPostShareRepository $shares,
        private readonly TokenCipher $cipher,
        private readonly SiteConfigService $config,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
    ) {}

    public function connection(User $author): ?XIntegration
    {
        return $this->integrations->findForUser($author);
    }

    public function hasShared(Post $post): bool
    {
        return $this->shares->findForPost($post) !== null
            || trim((string) $post->getCustomFieldValue(self::POST_ID_FIELD)) !== '';
    }

    /** Hash of the exact text that would be sent; the review button snapshots it into the queued message. */
    public function reviewHash(Post $post): string
    {
        return hash('sha256', $this->text($post));
    }

    /**
     * Publish one reviewed post for its own author. Returns its X post id, or null when the post is not
     * public, already claimed/shared, or was edited after review. Only called from an explicit publish action.
     *
     * The share row is claimed (unique post_id) before any remote call, so concurrent or retried
     * handlers cannot both post. Only a definite 4xx rejection or a pre-send failure releases the claim.
     */
    public function publish(Post $post, string $reviewedHash): ?string
    {
        if (!$post->isPublished() || $this->hasShared($post)) {
            return null;
        }
        $text = $this->text($post);
        if (!hash_equals(hash('sha256', $text), $reviewedHash)) {
            return null;
        }
        $integration = $this->connection($post->getAuthor())
            ?? throw new \RuntimeException('This post author has not connected an X account.');

        $conn = $this->em->getConnection();
        $postId = $post->getId();
        try {
            $conn->insert('x_post_share', [
                'post_id' => $postId,
                'integration_id' => $integration->getId(),
                'status' => XPostShare::PENDING,
                'text_hash' => $reviewedHash,
                'shared_at' => new \DateTimeImmutable(),
            ], ['shared_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $sending = false;
        try {
            // Refresh after claiming: X rotates refresh tokens, so concurrent refreshes would invalidate each other.
            $token = $this->accessToken($integration);
            $sending = true;
            $xPostId = $this->client->createPost($token, $text);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            if (!$sending || ($code >= 400 && $code < 500)) {
                $conn->delete('x_post_share', ['post_id' => $postId, 'status' => XPostShare::PENDING]);
            } else {
                $conn->update('x_post_share', [
                    'status' => XPostShare::UNKNOWN,
                    'error' => mb_substr($e->getMessage(), 0, 255),
                ], ['post_id' => $postId, 'status' => XPostShare::PENDING]);
            }

            throw $e;
        }

        try {
            $conn->update('x_post_share', ['status' => XPostShare::SENT, 'x_post_id' => $xPostId], ['post_id' => $postId]);
            $post->addCustomField((new CustomField())
                ->setFieldKey(self::POST_ID_FIELD)
                ->setFieldValue($xPostId));
            $this->em->flush();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('X post %s was created but not fully recorded for post %d; reconcile x_post_share manually.', $xPostId, $postId), 0, $e);
        }

        return $xPostId;
    }

    private function text(Post $post): string
    {
        $text = self::sanitizeText((string) $post->getCustomFieldValue(self::TEXT_FIELD));
        if ($text === '') {
            throw new \RuntimeException('Write and save an X post draft before sharing.');
        }

        return $text . "\n\n" . $this->postUrl($post);
    }

    public static function sanitizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';

        return trim($text);
    }

    private function accessToken(XIntegration $integration): string
    {
        $expiresAt = $integration->getTokenExpiresAt();
        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable('+60 seconds')) {
            $storedRefreshToken = $integration->getRefreshToken();
            $refreshToken = $storedRefreshToken === null ? null : $this->cipher->decrypt($storedRefreshToken);
            if ($refreshToken === null) {
                throw new \RuntimeException('The X token expired. Reconnect X in the admin.');
            }

            $tokens = $this->client->refreshToken($refreshToken);
            $integration->setAccessToken($this->cipher->encrypt($tokens['access_token']));
            if ($tokens['refresh_token'] !== null) {
                $integration->setRefreshToken($this->cipher->encrypt($tokens['refresh_token']));
            }
            $integration->setTokenExpiresAt($tokens['expires_in'] > 0
                ? new \DateTimeImmutable('+' . $tokens['expires_in'] . ' seconds')
                : null);
            $this->em->flush();
        }

        $token = $this->cipher->decrypt($integration->getAccessToken());
        if ($token === null) {
            throw new \RuntimeException('The stored X token cannot be decrypted. Reconnect X.');
        }

        return $token;
    }

    private function postUrl(Post $post): string
    {
        $date = $post->getPublishedAt() ?? $post->getCreatedAt();
        $path = $this->urls->generate('app_blog_post', [
            'year' => $date->format('Y'),
            'month' => $date->format('m'),
            'slug' => $post->getSlug(),
        ]);
        $base = rtrim((string) $this->config->get('site.url', ''), '/');

        return $base !== '' ? $base . $path : $this->urls->generate(
            'app_blog_post',
            ['year' => $date->format('Y'), 'month' => $date->format('m'), 'slug' => $post->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
