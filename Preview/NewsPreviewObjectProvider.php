<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Preview;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Manager\NewsManager;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use Sulu\Bundle\PreviewBundle\Preview\Object\PreviewObjectProviderInterface;
use Sulu\Component\Serializer\ArraySerializerInterface;

class NewsPreviewObjectProvider implements PreviewObjectProviderInterface
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NewsRepository $newsRepository,
        private readonly NewsManager $newsManager,
        private readonly ArraySerializerInterface $serializer,
    ) {
    }

    public function getObject($id, $locale)
    {
        $news = $this->newsRepository->findById($id, $locale); // load the object by id
        $this->entityManager->initializeObject($news);
        $this->entityManager->detach($news);
        return $news;
    }

    public function getId($object)
    {
        return $object->getId();
    }

    public function setValues($object, $locale, array $data)
    {
        // bind data-array to the object
        $this->entityManager->detach($object);
        $this->newsManager->hydrateWithData($object, $data);
    }

    public function setContext($object, $locale, array $context)
    {
        // if (\array_key_exists('template', $context)) {
        //   $object->setStructureType($context['template']);
        // }

        return $object;
    }

    public function serialize($object)
    {
        $serializedObject = $this->serializer->serialize(
            $object,
            SerializationContext::create()
                ->setSerializeNull(true)
                ->setGroups([
                    'Other',
                    NewsInterface::SERIALIZATION_GROUPS[0],
                ])
        );
        return \serialize([
            'id' => $object->getId(),
            'locale' => $object->getLocale(),
            'state' => $serializedObject,
        ]);
    }

    public function deserialize($serializedObject, $objectClass)
    {
        $object = \unserialize($serializedObject);
        $entity = $this->newsRepository->findById($object['id'], $object['locale']); // load the object by id
        $this->entityManager->detach($entity);
        $this->newsManager->hydrateWithData($entity, $object['state']);
        return $entity;
    }

    public function getSecurityContext($id, $locale): ?string
    {
        return NewsAdmin::SECURITY_CONTEXT;
    }
}
