<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Controller\Admin;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\PageBundle\Admin\PageAdmin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Contracts\Service\Attribute\SubscribedService;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Sulu\Component\Content\Document\Behavior\SecurityBehavior;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\DocumentManager\Exception\DocumentNotFoundException;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private SecurityCheckerInterface $securityChecker,
        private UrlGeneratorInterface $urlGenerator,
        private ?ViewRegistry $viewRegistry = null,
    ) {
    }


    public function checkNews(string $id, Request $request): Response
    {
        $locale = $this->getLocale($request);

        try {
            $this->securityChecker->checkPermission(
                new SecurityCondition(NewsAdmin::SECURITY_CONTEXT, $locale, null, null),
                PermissionTypes::EDIT
            );

            $user = $this->getUser();

            $view = $this->viewRegistry->findViewByName(NewsAdmin::EDIT_FORM_VIEW);
            $path = str_replace([':locale', ':id'], [$locale, $id], $view->getPath());

            return $this->json(data: [
                'status' => 'Access Granted',
                'user_locale' => $user->getLocale(),
                'edit_url' => $request->getSchemeAndHttpHost() . '/admin/#' . $path,
            ]);
        } catch (DocumentNotFoundException $ex) {
            return $this->json(data: ['status' => 'News not found'], status: 404);
        }
    }


    private function getSecurityCondition(Request $request, $document = null): SecurityCondition
    {
        return new SecurityCondition(
            PageAdmin::getPageSecurityContext($document->getWebspaceName()),
            $this->getLocale($request),
            SecurityBehavior::class,
            $request->get('id')
        );
    }

    public function getLocale(Request $request): ?string
    {
        return $request->query->has('locale')
            ? (string) $request->query->get('locale')
            : null;
    }
}
