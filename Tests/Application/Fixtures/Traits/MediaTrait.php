<?php

namespace App\Tests\Application\Fixtures\Traits;

use Doctrine\Persistence\ObjectManager;
use SplFileInfo;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait MediaTrait
{

    private function createMedia(
        ObjectManager $manager,
        Collection $collection,
        SplFileInfo $fileInfo,
        string $locale,
        MediaInterface $previewMedia = null,
    ): MediaInterface {
        $fileName = $fileInfo->getBasename();
        $title = $fileInfo->getFilename();
        $uploadedFile = new UploadedFile($fileInfo->getPathname(), $fileName);

        $storageOptions = $this->storage->save(
            $uploadedFile->getPathname(),
            $fileName,
        );

        $mediaType = $manager->getRepository(MediaType::class)->find(2);
        if (!$mediaType instanceof MediaType) {
            throw new \RuntimeException('MediaType "2" not found. Have you loaded the Sulu fixtures?');
        }

        $media = new Media();

        $file = new File();
        $file->setVersion(1)
            ->setMedia($media);

        $media->addFile($file)
            ->setType($mediaType)
            ->setCollection($collection);

        $fileVersion = new FileVersion();
        $fileVersion->setVersion($file->getVersion())
            ->setSize($uploadedFile->getSize())
            ->setName($fileName)
            ->setStorageOptions($storageOptions)
            ->setMimeType($uploadedFile->getMimeType() ?: 'image/jpeg')
            ->setFile($file);

        $file->addFileVersion($fileVersion);

        $fileVersionMeta = new FileVersionMeta();
        $fileVersionMeta->setTitle($title)
            ->setDescription('')
            ->setLocale($locale)
            ->setFileVersion($fileVersion);

        $fileVersion->addMeta($fileVersionMeta)
            ->setDefaultMeta($fileVersionMeta);

        if ($previewMedia) {
            $media->setPreviewImage($previewMedia);
        }

        $manager->persist($fileVersionMeta);
        $manager->persist($fileVersion);
        $manager->persist($media);

        return $media;
    }

    private function deleteMediaFromCollection(ObjectManager $manager, Collection $collection): void
    {

        // remove the physical files
        foreach ($collection->getMedia() as $media) {

            /** @var File $file */
            foreach ($media->getFiles() as $file) {
                /** @var FileVersion $fileVersion */
                foreach ($file->getFileVersions() as $fileVersion) {

                    $this->storage->remove($fileVersion->getStorageOptions());
                }
            }
        }
    }
}
