<?php

namespace Kotaru\Bundle\SuluNewsBundle\Tests\Functional\News\Controller;

use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Manager\NewsManager;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Kotaru\Bundle\SuluNewsBundle\Tests\Application\Fixtures\LoadNewsFixture;
use Kotaru\Bundle\SuluNewsBundle\Tests\Application\Fixtures\Traits\MediaTrait;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\ProxyReferenceRepository;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\RGB;
use JMS\Serializer\SerializationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use SplFileInfo;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionRepositoryInterface;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class NewsControllerTest extends SuluTestCase
{
    use MediaTrait;
    public const ROUTE_PREFIX = [
        'cs' => 'aktuality',
        'en' => 'news',
        'de' => 'nachricht',
    ];
    public const DEFAULT_LOCALE = 'cs';
    public const LOCALE_EN = 'en';
    public const LOCALE_DE = 'de';
    protected static $newsSerializationGroups = [
        'partialMedia',
        'apiNews',
    ];
    /** @var News[] */
    protected array $news = [];
    protected KernelBrowser $client;
    private StorageInterface $storage;
    protected EntityManagerInterface $em;

    public function setUp(): void
    {
        $this::createKernel(['sulu.context' => 'admin']);
        $this->client = static::createAuthenticatedClient();
        $this->storage = static::getContainer()->get('sulu_media.storage.local');
        $this->purgeDatabase();
        $this->initPhpcr();
        $this->loadFixtures();
        $this->loadNewsFixtures();
        $this->em = static::getEntityManager();
    }
    public function tearDown(): void
    {
        $this->cleanupNewsFixtures();
        $this->purgeDatabase();
        parent::tearDown();
    }


    protected function loadFixtures(): void
    {
        // loading base doctrine fixtures
        $entityManager = static::getEntityManager();
        $loader = static::getContainer()->get('doctrine.fixtures.loader');
        $purger = new ORMPurger();
        $executor = new ORMExecutor($entityManager, $purger);
        $referenceRepository = new ProxyReferenceRepository($entityManager);
        $executor->setReferenceRepository($referenceRepository);
        $executor->execute($loader->getFixtures());
    }
    protected function loadNewsFixtures(): void
    {
        $storage = static::getContainer()->get('sulu_media.storage.local');
        $routeManager = static::getContainer()->get('sulu_route.manager.route_manager');
        $categoryManager = static::getContainer()->get('sulu_category.category_manager');
        $tagManager = static::getContainer()->get('sulu_tag.tag_manager');

        $fixture = new LoadNewsFixture($storage, $routeManager, $categoryManager, $tagManager);
        $fixture->load(static::getEntityManager());
        $this->news = $fixture->getNews();
    }
    protected function cleanupNewsFixtures(): void
    {
        $storage = static::getContainer()->get('sulu_media.storage.local');
        $routeManager = static::getContainer()->get('sulu_route.manager.route_manager');
        $categoryManager = static::getContainer()->get('sulu_category.category_manager');
        $tagManager = static::getContainer()->get('sulu_tag.tag_manager');

        $fixture = new LoadNewsFixture($storage, $routeManager, $categoryManager, $tagManager);
        $fixture->cleanup(static::getEntityManager());
    }

    public function testGetUrl(): void
    {
        $this->client->request(
            method: 'GET',
            uri: \sprintf('/admin/api/news/%d?%s', $this->news[0]->getId(), \http_build_query(['locale' => $this->news[0]->getLocale()])),
        );
        $this->assertRouteSame('app.get_news', ['id' => $this->news[0]->getId()]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        $this->assertJson($response->getContent());
    }
    public function testGetUrlWithoutLocale(): void
    {
        $this->client->request(
            method: 'GET',
            uri: \sprintf('/admin/api/news/%d', $this->news[0]->getId()),
        );
        $this->assertRouteSame('app.get_news', ['id' => $this->news[0]->getId()]);
        $this->assertResponseStatusCodeSame(400);
        $response = $this->client->getResponse();
        $this->assertJson($response->getContent());
    }

    #[Depends('testGetUrl')]
    public function testGetResponse(): void
    {
        foreach ($this->news as $news) {
            $locale = $news->getLocale();
            $this->client->request(
                method: 'GET',
                uri: \sprintf('/admin/api/news/%d?%s', $news->getId(), \http_build_query(['locale' => $locale])),
            );

            $response = $this->client->getResponse();
            $json = $response->getContent();
            $this->assertJson($json);
            $newsObject = $this->getSerializedNews($news, $locale);
            $content = \json_decode($json, true);
            $this->assertArrayIsEqualToArrayIgnoringListOfKeys(
                $newsObject,
                $content,
                ['ext', 'tags', 'categories', 'source', 'external_url', 'ghostLocale'],
                'Returned response data does not equal to the serialized original data.'
            );
        }
    }
    public static function provideList(): array
    {
        return [
            [
                ['page' => 1, 'locale' => static::DEFAULT_LOCALE, 'limit' => 10],
                1,
            ],
            [
                ['page' => 1, 'locale' => static::DEFAULT_LOCALE, 'limit' => 1],
                1,
            ],
            [
                ['page' => 1, 'locale' => static::LOCALE_EN, 'showExternal' => 'true', 'limit' => 2],
                2,
            ],
            [
                ['page' => 3, 'locale' => static::DEFAULT_LOCALE],
                0,
            ],
            [
                ['page' => 1, 'locale' => static::DEFAULT_LOCALE, 'filter' => ['visible' => 'true'], 'limit' => 10],
                1,
            ],
        ];
    }

    #[DataProvider('provideList')]
    public function testGetList(array $params, int $expectedCount): void
    {
        $fields = ['image', 'title', 'id', 'publishDate', 'route'];
        $locale = static::DEFAULT_LOCALE;
        $queryParams = \http_build_query(['fields' => \join(',', $fields), 'locale' => $locale, 'limit' => 10, ...$params]);
        $this->client->request(
            method: 'GET',
            uri: \sprintf('/admin/api/news?%s', $queryParams),
        );
        $request = $this->client->getRequest();
        $response = $this->client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $content = \json_decode($json, true);
        $news = $content['_embedded']['news'];
        $this->assertCount($expectedCount, $news);

        $this->assertEquals($request->query->getInt('page', 1), $content['page']);
        if ($request->query->has('limit')) {
            $this->assertEquals($request->query->getInt('limit'), $content['limit']);
        }
        if (empty($news)) {
            return;
        }
        foreach ($news as $n) {
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $n);
            }
        }
    }

    public static function provideItems(): array
    {
        return [
            [
                [
                    "publishDate" => (new \DateTimeImmutable())->format('Y-m-d\\TH:i:s'),
                    "title" => "test",
                    "route" => "/" . static::ROUTE_PREFIX[static::DEFAULT_LOCALE] . "/test",
                    "description" => "<p>Popisek aktuality</p>",
                    "external_url" => null,
                    "content" => [
                        [
                            "type" => "textContent",
                            "text" => "<p>Obsah aktuality</p>"
                        ],
                        [
                            "type" => "video",
                            "external" => true,
                            "provider" => "youtube",
                            "embed" => "https://youtu.be/dQw4w9WgXcQ"
                        ]
                    ]
                ],
                static::DEFAULT_LOCALE,
            ],
            [
                [
                    "publishDate" => (new \DateTimeImmutable('2025-10-22T12:31:00'))->format('Y-m-d\\TH:i:s'),
                    "title" => "What are you looking at?",
                    "image" => "image_name",
                    "route" => "/" . static::ROUTE_PREFIX[static::LOCALE_EN] . "/what_are_you_looking_at",
                    "description" => "<p>The contents of this piece of news remains unknown to this day.</p>",
                    "external_url" => null,
                    "content" => [
                        [
                            "type" => "textContent",
                            "text" => "<p>This is content!</p>"
                        ],
                        [
                            "type" => "video",
                            "external" => true,
                            "provider" => "youtube",
                            "embed" => "https://youtu.be/dQw4w9WgXcQ"
                        ]
                    ]
                ],
                static::LOCALE_EN,
            ]
        ];
    }

    #[DataProvider('provideItems')]
    public function testPost(array $data, string $locale = self::DEFAULT_LOCALE): void
    {
        $jsonData = $data;
        if (isset($data['image'])) {
            // creating media
            $tempImage = $this->createRandomImage($data['image']);
            $collection = $this->getNewsCollection();
            $media = $this->createMedia($this->em, $collection, $tempImage, $locale);
            $this->em->flush();
            $jsonData = \array_merge($data, ['image' => ['id' => $media->getId()]]);
        }
        $queryParameters = \http_build_query(['locale' => $locale,]);
        $this->client->request(
            method: 'POST',
            uri: \sprintf('/admin/api/news?%s', $queryParameters),
            content: \json_encode($jsonData, \JSON_UNESCAPED_UNICODE),
        );
        $response = $this->client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);

        $news = $this->createMockNews($jsonData, $locale);
        $newsObject = $this->getSerializedNews($news, $locale);
        $content = \json_decode($json, true);

        $this->assertArrayIsEqualToArrayOnlyConsideringListOfKeys(
            $newsObject,
            $content,
            \array_filter(\array_keys($data), fn($d) => $d !== 'route'), // route will not match because new entity has duplicate route name
            'The returned news object does not match expected output.'
        );
        // cleanup
        if (isset($data['image'])) {
            \unlink((string) $tempImage);
        }
    }

    public static function providePostCopy(): array
    {
        return [
            [
                static::DEFAULT_LOCALE,
            ],
            [
                static::LOCALE_EN,
            ],
        ];
    }

    #[DataProvider('providePostCopy')]
    public function testPostCopy(string $locale): void
    {
        $news = $this->news[0];
        $this->client->request(
            method: 'POST',
            uri: \sprintf('/admin/api/news/%d?action=copy', $news->getId()),
        );
        $response = $this->client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $content = \json_decode($json, true);

        $this->em->refresh($news);

        $this->assertIsInt($content['id'], 'The returned response doesn\'t contain valid id');
        $this->assertNotEquals($news->getId(), $content['id'], 'The returned id not different than the id of the original entity.');
    }

    public static function providePostLocaleCopy(): array
    {
        return [
            [
                static::DEFAULT_LOCALE,
                null,
                [
                    static::LOCALE_DE,
                ]
            ],
            [
                static::LOCALE_EN,
                static::DEFAULT_LOCALE,
                [
                    static::LOCALE_EN,
                    static::LOCALE_DE,
                ]
            ],
        ];
    }

    #[DataProvider('providePostLocaleCopy')]
    public function testPostLocaleCopy(string $locale, ?string $localeFrom, array $targetlocales): void
    {
        $queryParameters = \http_build_query(['locale' => $locale, 'src' => $localeFrom, 'dest' => \join(',', $targetlocales)]);
        $news = $this->news[0];
        $this->client->request(
            method: 'POST',
            uri: \sprintf('/admin/api/news/%d?action=copy-locale&%s', $news->getId(), $queryParameters),
        );
        $response = $this->client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $content = \json_decode($json, true);
        $localeFrom ??= $locale;

        // test the returned locale entity
        if ($locale !== $localeFrom) {
            // the original news might not have content in the locale
            $this->em->refresh($news);
        }
        $newsObject = $this->getSerializedNews($news, $locale);
        $this->assertArrayIsEqualToArrayIgnoringListOfKeys(
            $newsObject,
            $content,
            ['availableLocales', 'contentLocales'],
            'The returned news object after locale copy does not match expected output.'
        );

        // test all copies

        $this->em->refresh($news);

        foreach ($targetlocales as $tLocale) {
            $this->client->request(
                method: 'GET',
                uri: \sprintf('/admin/api/news/%d?%s', $news->getId(), \http_build_query(['locale' => $tLocale])),
            );
            $newsObject = $this->getSerializedNews($news, $tLocale);

            $response = $this->client->getResponse();
            $json = $response->getContent();
            $this->assertJson($json);

            $content = \json_decode($json, true);
            $this->assertArrayIsEqualToArrayIgnoringListOfKeys(
                $newsObject,
                $content,
                ['availableLocales', 'route', 'image', 'contentLocales'],
                \sprintf('The locale copy of news for locale "%s" does not match expected output.', $tLocale)
            );
        }
    }

    public static function providePostToggles(): array
    {
        return [
            [
                'enable',
                static::DEFAULT_LOCALE,
            ],
            [
                'disable',
                static::DEFAULT_LOCALE,
            ],
            [
                'set-external',
                static::DEFAULT_LOCALE,
            ],
            [
                'unset-external',
                static::LOCALE_DE,
            ],
        ];
    }

    #[DataProvider('providePostToggles')]
    public function testPostToggles(string $action, string $locale): void
    {
        $queryParameters = \http_build_query(['action' => $action, 'locale' => $locale]);
        $news = $this->news[0];
        $this->client->request(
            method: 'POST',
            uri: \sprintf('/admin/api/news/%d?%s', $news->getId(), $queryParameters),
        );
        $response = $this->client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $content = \json_decode($json, true);

        $actionEffect = [
            'enable' => ['visible' => true],
            'disable' => ['visible' => false],
            'set-external' => ['external' => true],
            'unset-external' => ['external' => false],
        ];
        $expectedValue = $actionEffect[$action];

        $newsObject = $this->getSerializedNews($news, $locale);
        $this->assertArrayIsEqualToArrayIgnoringListOfKeys(
            \array_merge($newsObject, $expectedValue),
            $content,
            ['availableLocales', 'contentLocales'],
            'The returned news object after executing action does not match expected output.'
        );
    }

    #[DataProvider('provideItems')]
    public function testPut(array $data, string $locale = self::DEFAULT_LOCALE): void
    {
        $jsonData = $data;
        $news = $locale === $this::DEFAULT_LOCALE ? $this->news[0] : $this->news[1];

        if (isset($data['image'])) {
            // creating media
            $tempImage = $this->createRandomImage($data['image']);
            $collection = $this->getNewsCollection();
            $media = $this->createMedia($this->em, $collection, $tempImage, $locale);
            $this->em->flush();
            $jsonData = \array_merge($data, ['image' => ['id' => $media->getId()]]);
        }
        $queryParameters = \http_build_query(['locale' => $locale]);
        $this->client->request(
            method: 'PUT',
            uri: \sprintf('/admin/api/news/%d?%s', $news->getId(), $queryParameters),
            content: \json_encode($jsonData, \JSON_UNESCAPED_UNICODE),
        );
        $response = $this->client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);

        $news = $this->setNewsData($news, $jsonData, $locale);
        $newsObject = $this->getSerializedNews($news, $locale);
        $content = \json_decode($json, true);

        $this->assertArrayIsEqualToArrayOnlyConsideringListOfKeys(
            $newsObject,
            $content,
            \array_filter(\array_keys($data), fn($d) => $d !== 'route'), // route will not match because new entity has duplicate route name
            'The returned news object does not match expected output.'
        );
        // cleanup
        if (isset($data['image'])) {
            \unlink((string) $tempImage);
        }
    }

    public function testDelete(): void
    {
        $news = $this->news[0];
        $id = $news->getId();
        $locale = $news->getLocale();
        $this->client->request(
            method: 'DELETE',
            uri: \sprintf('/admin/api/news/%d?%s', $news->getId(), \http_build_query(['deleteLocale' => 'false', 'locale' => $locale])),
        );
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(204);

        $newsManager = $this->getContainer()->get(NewsManager::class);
        $this->assertNull($newsManager->get($id, $locale));
    }
    public function testDeleteLocale(): void
    {
        $news = $this->news[1];
        $id = $news->getId();
        $locale = $news->getLocale();
        $this->client->request(
            method: 'DELETE',
            uri: \sprintf('/admin/api/news/%d?%s', $news->getId(), \http_build_query(['deleteLocale' => 'true', 'locale' => $locale])),
        );
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $newsManager = $this->getContainer()->get(NewsManager::class);
        $modifiedNews = $newsManager->get($id, $locale);
        $this->assertNull($modifiedNews->getTranslation($locale));
    }

    protected function setNewsData(NewsInterface $entity, $data, $locale): NewsInterface
    {
        $newsManager = $this->getContainer()->get(NewsManager::class);
        $entity->setLocale($locale);
        $entity->setDefaultLocale($locale);
        $newsManager->mapDataToEntity($data, $entity);
        return $entity;
    }
    protected function createMockNews($data, $locale): NewsInterface
    {
        $entity = new News();
        $this->setNewsData($entity, $data, $locale);
        return $entity;
    }
    protected function getSerializedNews(NewsInterface|\Kotaru\Bundle\SuluNewsBundle\Api\News $entity, $locale): array
    {
        $apiObject = $entity;
        if (!($entity instanceof \Kotaru\Bundle\SuluNewsBundle\Api\News)) {
            $apiObject = new \Kotaru\Bundle\SuluNewsBundle\Api\News($entity, $locale);
        }

        $mediaManager = $this->getContainer()->get('sulu_media.media_manager');

        if (null !== $apiObject->getEntity()->getImage()) {
            $apiObject->setImage(
                $mediaManager->addFormatsAndUrl(new Media($apiObject->getEntity()->getImage(), $locale, null))
            );
        }
        $context = SerializationContext::create()
            ->setGroups(static::$newsSerializationGroups)
            ->setSerializeNull(true);

        $jms = $this->getContainer()->get('jms_serializer');
        return $jms->toArray($apiObject, $context);
    }
    protected function getNewsCollection()
    {
        /** @var CollectionRepositoryInterface */
        $collectionRepository = $this->em->getRepository(CollectionInterface::class);
        $collection = $collectionRepository->findCollectionByKey('app.news');
        // $this->em->initializeObject($collection); // find the populate the media
        return $collection;
    }
    protected function createRandomImage($fileName): SplFileInfo
    {
        /** @var ImagineInterface */
        $imagine = $this->getContainer()->get('sulu_media.adapter');
        // $mediaPath = $this->getContainer()->getParameter('sulu_media.media.storage.local.path');
        $image = $imagine->create(new Box(512, 512), (new RGB())->color('#123456'));
        $imagePath = \tempnam(\sys_get_temp_dir(), 'news-controller-test_') . '-' . $fileName . '.jpg';

        $image->save($imagePath, []);
        return new SplFileInfo($imagePath);
    }
}
