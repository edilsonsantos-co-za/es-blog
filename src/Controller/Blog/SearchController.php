<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Entity\TopicSuggestion;
use App\Service\PostService;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.topic_suggest')] private readonly RateLimiterFactory $suggestLimiter,
    ) {}

    #[Route('/search', name: 'app_blog_search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query   = trim($request->query->getString('q', ''));
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = null;

        if ($query !== '') {
            $posts = $this->postService->search($query, $page, $perPage);
        }

        return $this->render('blog/search/index.html.twig', [
            'q'     => $query,
            'posts' => $posts,
            'suggest' => ['topic' => $query, 'details' => '', 'email' => '', 'error' => null, 'sent' => $request->query->getBoolean('suggested')],
        ]);
    }

    /** A reader suggests a topic (the form on the search page). Post/redirect/get, so a refresh never sends it twice. */
    #[Route('/search/suggest', name: 'app_blog_suggest', methods: ['POST'])]
    public function suggest(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('topic_suggest', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $topic   = trim(preg_replace('/\s+/u', ' ', $request->request->getString('topic')) ?? '');
        $details = trim($request->request->getString('details'));
        $email   = trim($request->request->getString('email'));
        $error   = null;

        // Honeypot: people never see or fill "website"; pretend it worked
        if ($request->request->getString('website') !== '') {
            return $this->redirectToRoute('app_blog_search', ['suggested' => 1, '_fragment' => 'suggest']);
        }

        if (mb_strlen($topic) < 3) {
            $error = 'Tell me the topic in a few words.';
        } elseif (mb_strlen($topic) > TopicSuggestion::TOPIC_MAX) {
            $error = sprintf('Keep the topic under %d characters; the details box takes the rest.', TopicSuggestion::TOPIC_MAX);
        } elseif (mb_strlen($details) > TopicSuggestion::DETAILS_MAX) {
            $error = sprintf('Keep the details under %d characters.', TopicSuggestion::DETAILS_MAX);
        } elseif ($email !== '' && (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'That email doesn’t look right. It’s optional, so you can leave it empty.';
        } elseif (!$this->suggestLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $error = 'Thanks for all the ideas! Please wait a while before sending more.';
        }

        if ($error !== null) {
            return $this->render('blog/search/index.html.twig', [
                'q' => '', 'posts' => null,
                'suggest' => ['topic' => $topic, 'details' => $details, 'email' => $email, 'error' => $error, 'sent' => false],
            ], new Response('', $error === 'Thanks for all the ideas! Please wait a while before sending more.' ? 429 : 422));
        }

        $this->em->persist(new TopicSuggestion($topic, $details !== '' ? $details : null, $email !== '' ? mb_strtolower($email) : null));
        $this->em->flush();

        return $this->redirectToRoute('app_blog_search', ['suggested' => 1, '_fragment' => 'suggest']);
    }
}
