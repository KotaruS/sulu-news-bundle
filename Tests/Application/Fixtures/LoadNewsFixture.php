<?php

namespace Kotaru\Bundle\SuluNewsBundle\Tests\Application\Fixtures;

use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsTranslation;
use Kotaru\Bundle\SuluNewsBundle\Tests\Application\Fixtures\Traits\MediaTrait;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionRepositoryInterface;
use Sulu\Bundle\RouteBundle\Manager\RouteManagerInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Symfony\Component\Finder\Finder;
use Doctrine\Persistence\ObjectManager;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Symfony\Component\Finder\SplFileInfo;
use Sulu\Bundle\MediaBundle\Entity\MediaType;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\CollectionMeta;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;

class LoadNewsFixture
{
    use MediaTrait;

    final public const LOCALE_EN = 'en';
    final public const LOCALE_CS = 'cs';

    /** @var News[] */
    private array $newsCollection = [];

    public function __construct(
        private readonly StorageInterface $storage,
        private RouteManagerInterface $routeManager,
        private CategoryManagerInterface $categoryManager,
        private TagManagerInterface $tagManager
    ) {}

    /**
     * @return News[]
     */
    public function getNews(): array
    {
        return $this->newsCollection;
    }

    public function load(ObjectManager $manager): void
    {
        if (!empty($this->newsCollection)) {
            return;
        }
        $images = $this->loadImages($manager, 'News');
        $locale = static::LOCALE_CS;
        $categories = $this->createCategories();
        $tags = $this->createTags();

        $news = new News();
        $news2 = new News();
        $this->newsCollection = [$news, $news2];
        $news->setDefaultLocale($locale);
        $news2->setDefaultLocale(static::LOCALE_EN);
        $news->setLocale($locale);
        $news2->setLocale(static::LOCALE_EN);

        $news
            ->setTitle('Basic Test News Article')
            ->setDescription('<p>Lorem ipsum sit dolor amet</p>')
            ->setPublishDate(new \DateTimeImmutable())
            ->setImage($images['smile.png'])
            ->setExtension([
                'seo' => [
                    'title' => null,
                    'description' => null,
                    'keywords' => null,
                    'canonicalUrl' => null,
                    'noIndex' => null,
                    'noFollow' => null,
                    'hideInSitemap' => null,
                ],
                'excerpt' => [
                    'title' => null,
                    'description' => null,
                ],
            ])
            ->setContent([
                [
                    'type' => 'heading',
                    'headingType' => 'h2',
                    'alignment' => 'middle',
                    'text' => 'This is a heading!',
                ],
                [
                    'type' => 'textContent',
                    'text' => '<p>Hello there, this is News\' content</p>',
                ],
            ])
            ->setVisible(true)
            ->addCategory($categories[0])
            ->addTag($tags[1])
            ->addTag($tags[2])
        ;
        $news2
            ->setTitle('Common News Article')
            ->setDescription('<p>Quo ratione maiores neque quibusdam nobis temporibus maxime ducimus commodi, dolore ad doloribus?</p>')
            ->setPublishDate(new \DateTimeImmutable())
            ->setImage($images['avatar.jpg'])
            ->setExtension([
                'seo' => [
                    'title' => null,
                    'description' => null,
                    'keywords' => null,
                    'canonicalUrl' => null,
                    'noIndex' => null,
                    'noFollow' => null,
                    'hideInSitemap' => null,
                ],
                'excerpt' => [
                    'title' => 'Common News',
                    'description' => null,
                ],
            ])
            ->setContent([
                [
                    'type' => 'textContent',
                    'text' => '<p>Lorem ipsum, dolor sit amet consectetur adipisicing elit.</p>',
                ],
                [
                    'type' => 'heading',
                    'headingType' => 'h2',
                    'alignment' => 'middle',
                    'text' => 'Switcheroo heading!',
                ],
            ])
            ->setVisible(false)
            ->addCategory($categories[1])
            ->setExternal(true)
            ->setSource('https://fifty-50.cz')
        ;
        $manager->persist($news);
        $manager->persist($news2);
        $manager->flush();
        // must flush before creating route
        $this->routeManager->create($news, '/aktuality/basic-news');
        $this->routeManager->create($news2, '/news/common-news');
        $manager->persist($news);
        $manager->flush();
    }
    public function cleanup(ObjectManager $manager): void
    {
        if (!empty($this->newsCollection)) {
            return;
        }
        /** @var CollectionRepositoryInterface */
        $collectionRepository = $manager->getRepository(CollectionInterface::class);

        $collection = $collectionRepository->findCollectionByKey('app.news');

        $this->deleteMediaFromCollection($manager, $collection);
    }

    /**
     * @return MediaInterface[]
     */
    private function loadImages(ObjectManager $manager, string $title): array
    {

        $collection = $this->createCollection(
            $manager,
            ['title' => $title],
        );
        $finder = new Finder();
        foreach ($finder->files()->in(__DIR__ . '/images/' . \strtolower($title)) as $file) {
            $media[\pathinfo($file->getPathname(), \PATHINFO_BASENAME)] = $this->createMedia($manager, $collection, $file, static::LOCALE_CS);
        }
        $manager->flush();
        $manager->refresh($collection);
        return $media;
    }



    /**
     * @param mixed[] $data
     */
    private function createCollection(ObjectManager $manager, array $data): CollectionInterface
    {
        $collection = new Collection();

        $collectionType = $manager->getRepository(CollectionType::class)->find(1);

        if (!$collectionType instanceof CollectionType) {
            throw new \RuntimeException('CollectionType "1" not found. Have you loaded the Sulu fixtures?');
        }
        $cleanName = \preg_replace('/([^A-z0-9\-])*/', '', \str_replace(' ', '-', \strtolower($data['title'])));

        $collection->setType($collectionType);

        $meta = new CollectionMeta();
        $meta->setLocale(self::LOCALE_CS);
        $meta->setTitle($data['title']);
        $meta->setCollection($collection);

        $collection->addMeta($meta);
        $collection->setKey('app.' . $cleanName);
        $collection->setDefaultMeta($meta);

        $manager->persist($collection);
        $manager->persist($meta);
        $manager->flush();

        return $collection;
    }


    private function createCategories(): array
    {
        $mainCategory = $this->categoryManager->save([
            'name' => 'Aktuality',
            'key' => 'news',
        ], null, static::LOCALE_CS, false);
        $this->categoryManager->save([
            'name' => 'News',
            'id' => $mainCategory->getId(),
        ], null, static::LOCALE_EN, false);

        $category1 = $this->categoryManager->save([
            'name' => 'Kategorie',
            'key' => 'news_test1',
        ], null, static::LOCALE_CS, false);
        $this->categoryManager->save([
            'name' => 'Test Category 1',
            'id' => $category1->getId(),
        ], null, static::LOCALE_EN, false);

        $category2 = $this->categoryManager->save([
            'name' => 'Název kategorie 2',
            'key' => 'news_test2',
            'parent' => $mainCategory->getId(),
        ], null, static::LOCALE_CS, false);
        $this->categoryManager->save([
            'name' => 'Category Title 2',
            'id' => $category2->getId(),
        ], null, static::LOCALE_EN, false);

        return [$category1, $category2];
    }
    private function createTags(): array
    {
        return \array_map(
            fn($tagName) => $this->tagManager->findOrCreateByName($tagName),
            ['První tag', 'news.extra', 'Aktualita roku']
        );
    }
}
