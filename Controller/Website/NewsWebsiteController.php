<?php

namespace Kotaru\Bundle\SuluNewsBundle\Controller\Website;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\SuluUtils\Repository\SettingsRepository;
use JMS\Serializer\SerializationContext;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\PreviewBundle\Preview\Preview;
use Sulu\Bundle\WebsiteBundle\Resolver\RequestAnalyzerResolverInterface;
use Sulu\Component\Serializer\ArraySerializerInterface;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsWebsiteController extends AbstractController
{
    protected ?string $locale = null;

    public function indexAction($entity, $attributes = [], $preview = false, $partial = false): Response
    {
        if (!$entity) {
            throw new NotFoundHttpException();
        }
        $request = $this->getRequest();

        // extract format twig file
        if (!$preview) {
            $requestFormat = $request->getRequestFormat();
        } else {
            $requestFormat = 'html';
        }
        $shouldRedirect = $entity->isExternal() == true && \preg_match('/https?:\\/\\//', (string) $entity->getSource());
        if ($shouldRedirect) {
            return new RedirectResponse($entity->getSource(), 301, ['Cache-Control' => 'max-age=604800, must-revalidate']);
        }

        $viewTemplate = $this->getTemplate(NewsInterface::RESOURCE_KEY, $requestFormat);

        $data = $this->getAttributes($attributes);
        $serializer = $this->container->get('array_serializer');
        $entityData = $serializer->serialize($entity, $this->getSerializationContext());

        if (isset($entityData['image'])) {
            $entityData['image'] = $this->loadMedia((int) $entityData['image']['id']);
        }
        $data = array_merge($data, ['content' => $entityData]);

        if ($partial) {
            $content = $this->renderBlockView(
                $viewTemplate,
                'content',
                $data
            );
        } elseif ($preview) {
            $content = $this->renderPreview(
                $viewTemplate,
                $data
            );
        } else {
            $content = $this->renderView(
                $viewTemplate,
                $data
            );
        }

        return new Response($content);
    }

    protected function getAttributes($attributes)
    {
        $webspaceManager = $this->container->get('sulu.webspace_manager');
        $requestAnalyzer = $this->container->get('sulu_core.webspace.request_analyzer');
        $requestAnalyzerResolver = $this->container->get('sulu_core.webspace.request_analyzer_resolver');
        $currentLocale = $requestAnalyzer->getCurrentLocalization()->getLocale();
        $this->locale = $currentLocale;
        $localizations = [];
        $webspace = $requestAnalyzer->getWebspace();

        if (null !== ($portal = $requestAnalyzer->getPortal())) {
            $allLocalizations = $portal->getLocalizations();
        } else {
            $allLocalizations = $webspace->getLocalizations();
        }

        foreach ($allLocalizations as $localization) {
            $locale = $localization->getLocale();

            $url = $webspaceManager->findUrlByResourceLocator('/', null, $locale);

            $localizations[$locale] = [
                'locale' => $locale,
                'url' => $url,
                'country' => $localization->getCountry(),
                'alternate' => false,
            ];
        }

        $attributes['locale'] = $currentLocale;
        $attributes['localizations'] = $localizations;
        $attributes['webspaceKey'] = $webspace->getKey();
        $attributes = \array_merge($attributes, $requestAnalyzerResolver->resolve($requestAnalyzer));

        return $attributes;
    }


    /**
     * Returns rendered part of template specified by block.
     *
     * @param string $view
     * @param string $block
     * @param array<string, mixed> $parameters
     */
    protected function renderBlockView($view, $block, $parameters = []): string
    {
        $twig = $this->container->get('twig');
        $parameters = $twig->mergeGlobals($parameters);

        $template = $twig->load($view);

        $level = \ob_get_level();
        \ob_start();

        try {
            $rendered = $template->renderBlock($block, $parameters);
            \ob_end_clean();

            return $rendered;
        } catch (\Exception $e) {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }

            throw $e;
        }
    }

    protected function renderPreview(string $view, array $parameters = []): string
    {
        $parameters['previewParentTemplate'] = $view;
        $parameters['previewContentReplacer'] = Preview::CONTENT_REPLACER;

        return $this->renderView('@SuluWebsite/Preview/preview.html.twig', $parameters);
    }

    protected function getTemplate(string $key, string $format): string
    {
        $viewTemplate = $key . '/index.' . $format . '.twig';
        $twig = $this->container->get('twig');

        if (!$twig->getLoader()->exists($viewTemplate)) {
            return $key . '/index.html.twig';
        }
        return $viewTemplate;
    }

    protected function getRequest(): Request
    {
        return $this->container->get('request_stack')->getCurrentRequest();
    }

    protected function loadMedia(int $mediaId)
    {
        return $this->container->get('media_manager')->getById($mediaId, $this->locale);
    }

    protected function getSerializationContext(): SerializationContext
    {
        return SerializationContext::create()->setSerializeNull(true)
            ->setGroups([
                'Default',
                NewsInterface::SERIALIZATION_GROUPS[0],
            ]);
    }

    public static function getSubscribedServices(): array
    {
        $subscribedServices = parent::getSubscribedServices();
        $subscribedServices['settings_repository'] = SettingsRepository::class;
        $subscribedServices['media_manager'] = MediaManagerInterface::class;
        $subscribedServices['sulu.webspace_manager'] = WebspaceManagerInterface::class;
        $subscribedServices['sulu_core.webspace.request_analyzer'] = RequestAnalyzerInterface::class;
        $subscribedServices['array_serializer'] = ArraySerializerInterface::class;
        $subscribedServices['sulu_core.webspace.request_analyzer_resolver'] = RequestAnalyzerResolverInterface::class;

        return $subscribedServices;
    }
}
