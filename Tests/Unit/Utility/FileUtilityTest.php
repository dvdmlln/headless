<?php

/*
 * This file is part of the "headless" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FriendsOfTYPO3\Headless\Tests\Unit\Utility;

use FriendsOfTYPO3\Headless\Resource\Rendering\YouTubeRenderer;
use FriendsOfTYPO3\Headless\Utility\File\ProcessingConfiguration;
use FriendsOfTYPO3\Headless\Utility\FileUtility;
use InvalidArgumentException;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Throwable;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\Rendering\RendererRegistry;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Extbase\Service\ImageService;

use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Typolink\LinkResult;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use UnexpectedValueException;

use function array_merge;

class FileUtilityTest extends UnitTestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy|ContentObjectRenderer
     */
    protected $contentObjectRenderer;

    public function testGetAbsoluteUrl(): void
    {
        $normalizedParams = $this->prophesize(NormalizedParams::class);
        $urlDomain = 'https://test-frontend.tld';
        $normalizedParams->getSiteUrl()->shouldBeCalled(1)->willReturn($urlDomain . '/test-site');
        $normalizedParams->getRequestHost()->shouldBeCalled(1)->willReturn($urlDomain);
        $fileUtility = $this->getFileUtility($normalizedParams);

        self::assertSame(
            'https://test-frontend.tld/test-site/fileadmin/test-video-file.mp4',
            $fileUtility->getAbsoluteUrl('/fileadmin/test-video-file.mp4')
        );

        $normalizedParams = $this->prophesize(NormalizedParams::class);
        $normalizedParams->getSiteUrl()->shouldBeCalled(1)->willReturn($urlDomain . '/test-site#asdasdas');
        $normalizedParams->getRequestHost()->shouldBeCalled(1)->willReturn($urlDomain);
        $fileUtility = $this->getFileUtility($normalizedParams);
        self::assertSame(
            'https://test-frontend.tld/test-site#asdasdas/fileadmin/#test-video#-file.mp#4',
            $fileUtility->getAbsoluteUrl('/fileadmin/#test-video#-file.mp#4')
        );

        $testSameUrl = 'https://test-frontend3.tld/fileadmin/test-video-file.mp4';
        self::assertSame($testSameUrl, $fileUtility->getAbsoluteUrl($testSameUrl));
    }

    public function testProcessFile()
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['headless.assetsCacheBusting'] = true;
        $fileData = [
            'uid' => 103,
            'pid' => 0,
            'missing' => 0,
            'type' => '2',
            'storage' => 1,
            'identifier' => '/test-file.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'name' => 'test-file.jpg',
            'size' => 72392,
            'creation_date' => 1639061876,
            'modification_date' => 1639061876,
            'crop' => null,
            'width' => 526,
            'height' => 526,
        ];

        $fileReferenceData = $this->getFileReferenceBaselineData();

        $file = $this->getMockFileForData($fileData);
        $processedFile = $this->getMockProcessedFileForData($fileData);
        $imageService = $this->getImageServiceWithProcessedFile($file, $processedFile);
        $fileUtility = $this->getFileUtility(null, $imageService);

        self::assertSame($this->getBaselineResultArrayForFile(), $fileUtility->processFile($file));

        $fileReference = $this->getMockFileReferenceForData($fileReferenceData);
        $processedFile = $this->getMockProcessedFileForData($fileReferenceData);
        $imageService = $this->getImageServiceWithProcessedFile($fileReference, $processedFile);
        $fileUtility = $this->getFileUtility(null, $imageService);

        self::assertSame($this->getBaselineResultArrayForFileReference(), $fileUtility->processFile($fileReference));

        $link = 'https://test.domain.tld/resource';
        $linkResult = new LinkResult(LinkService::TYPE_PAGE, 'https://test.domain.tld/resource');
        $file = $this->getMockFileForData($fileData, [
            'extension' => 'jpg',
            'title' => null,
            'alternative' => null,
            'description' => null,
            'link' => 123,
        ]);
        $processedFile = $this->getMockProcessedFileForData($fileData);
        $imageService = $this->getImageServiceWithProcessedFile($file, $processedFile);
        $contentObjectRenderer = $this->prophesize(ContentObjectRenderer::class);
        $contentObjectRenderer->typoLink(Argument::any(), Argument::any())->willReturn($linkResult);
        $fileUtility = $this->getFileUtility(null, $imageService, $contentObjectRenderer);
        $overwrittenBaseline = $this->getBaselineResultArrayForFile();
        $overwrittenBaseline['properties']['link'] = $link;
        $overwrittenBaseline['properties']['linkData'] = $linkResult;
        self::assertSame($overwrittenBaseline, $fileUtility->processFile($file));

        // CASE when typolink of ContentObjectRenderer returns '' instead of LinkResult
        $link = null;
        $linkResult = '';
        $file = $this->getMockFileForData($fileData, [
            'extension' => 'jpg',
            'title' => null,
            'alternative' => null,
            'description' => null,
            'link' => 123,
        ]);
        $processedFile = $this->getMockProcessedFileForData($fileData);
        $imageService = $this->getImageServiceWithProcessedFile($file, $processedFile);
        $contentObjectRenderer = $this->prophesize(ContentObjectRenderer::class);
        $contentObjectRenderer->typoLink(Argument::any(), Argument::any())->willReturn($linkResult);
        $fileUtility = $this->getFileUtility(null, $imageService, $contentObjectRenderer);
        $overwrittenBaseline = $this->getBaselineResultArrayForFile();
        $overwrittenBaseline['properties']['link'] = $link;
        $overwrittenBaseline['properties']['linkData'] = $linkResult;
        self::assertSame($overwrittenBaseline, $fileUtility->processFile($file));

        $fileReference = $this->getMockFileReferenceForData($fileReferenceData, 'video');
        $fileUtility = $this->getFileUtility();

        self::assertSame(
            $this->getBaselineResultArrayForVideoFileReference(),
            $fileUtility->processFile($fileReference)
        );
        $rendererFileUrl = 'https://renderer.public.url.tld/youtube';
        $fileReference = $this->getMockFileReferenceForData($fileReferenceData, 'video');
        $rendererRegistry = $this->prophesize(RendererRegistry::class);
        $fileRenderer = $this->prophesize(YouTubeRenderer::class);
        $fileRenderer->render($fileReference, '', '', ['returnUrl' => true])->willReturn($rendererFileUrl);
        $rendererRegistry->getRenderer($fileReference)->willReturn($fileRenderer->reveal());
        $fileUtility = $this->getFileUtility(null, null, null, $rendererRegistry);

        $overwrittenBaseline = $this->getBaselineResultArrayForVideoFileReference();
        $overwrittenBaseline['publicUrl'] = $rendererFileUrl;
        self::assertSame(
            $overwrittenBaseline,
            $fileUtility->processFile($fileReference)
        );
    }

    public function testCustomProcessingOptions(): void
    {
        $fileData = [
            'uid' => 103,
            'pid' => 0,
            'missing' => 0,
            'type' => '2',
            'storage' => 1,
            'identifier' => '/test-file.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'name' => 'test-file.jpg',
            'size' => 72392,
            'creation_date' => 1639061876,
            'modification_date' => 1639061876,
            'crop' => '',
            'width' => 526,
            'height' => 526,
        ];

        $fileReferenceData = $this->getFileReferenceBaselineData();

        $file = $this->getMockFileForData($fileData);
        $processedFile = $this->getMockProcessedFileForData($fileData);
        $imageService = $this->getImageServiceWithProcessedFile($file, $processedFile);
        $fileUtility = $this->getFileUtility(null, $imageService);

        $options = ['legacyReturn' => 0, 'cacheBusting' => 1];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'title' => null,
            'alternative' => null,
            'description' => null,
            'link' => null,
            'mimeType' => 'image/jpeg',
            'type' => 'image',
            'filename' => 'test-file.jpg',
            'originalUrl' => '/fileadmin/test-file.jpg',
            'uidLocal' => 103,
            'fileReferenceUid' => null,
            'size' => '71 KB',
            'dimensions' =>
                [
                    'width' => 526,
                    'height' => 526,
                ],
            'cropDimensions' =>
                [
                    'width' => 526,
                    'height' => 526,
                ],
            'crop' => null,
            'autoplay' => null,
            'extension' => 'jpg',
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = ['legacyReturn' => 0, 'cacheBusting' => 1, 'properties.' => ['byType' => 1]];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'title' => null,
            'alternative' => null,
            'description' => null,
            'link' => null,
            'mimeType' => 'image/jpeg',
            'type' => 'image',
            'uidLocal' => 103,
            'fileReferenceUid' => null,
            'size' => '71 KB',
            'dimensions' =>
                [
                    'width' => 526,
                    'height' => 526,
                ],
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'includeOnly' => 'alternative,type,width,height'],
        ];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'alternative' => null,
            'type' => 'image',
            'dimensions' => [
                'width' => 526,
                'height' => 526,
            ],
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'includeOnly' => 'alternative as alt,type,width,height', 'flatten' => 1],
        ];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'alt' => null,
            'type' => 'image',
            'width' => 526,
            'height' => 526,
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'includeOnly' => 'alternative as alt,type,width,height', 'flatten' => 1],
            'autogenerate.' => [
                'urlTest' => ['factor' => 2],
            ],
        ];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'alt' => null,
            'type' => 'image',
            'width' => 526,
            'height' => 526,
            'urlTest' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'defaultFieldsByType' => 'width', 'height'],
        ];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'link' => null,
            'dimensions' => [
                'width' => 526,
                'height' => 526,
            ],
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'defaultFieldsByType' => 'width,height', 'defaultImageFields' => 'dimensions,mimeType'],
        ];

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'mimeType' => 'image/jpeg',
            'dimensions' => [
                'width' => 526,
                'height' => 526,
            ],
        ], $fileUtility->process($file, ProcessingConfiguration::fromOptions($options)));

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'includeOnly' => 'alternative as alt,type,width,height', 'flatten' => 1],
            'conditionalCropVariant' => 1,
            'autogenerate.' => [
                'urlTest' => ['factor' => 2],
            ],
        ];

        $processedFile = $fileUtility->process($file, ProcessingConfiguration::fromOptions($options));

        $file = $this->getMockFileForData(
            $fileData,
            ['crop' => '{"default":{"cropArea":{"x":1,"y":1,"width":2,"height":2},"selectedRatio":"NaN","focusArea":null},"mobile":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}']
        );

        $processedFileMock = $this->getMockProcessedFileForData($fileData);

        $cropVariantCollection = CropVariantCollection::create((string)$file->getProperty('crop'));
        $cropArea = $cropVariantCollection->getCropArea('default');

        $imageService = $this->getImageServiceWithProcessedFile($file, $processedFileMock, [
            [
                'width' => 0,
                'height' => 0,
                'minWidth' => 0,
                'minHeight' => 0,
                'maxWidth' => 0,
                'maxHeight' => 0,
                'crop' => $cropArea,
                'fileExtension' => null,
            ],
        ]);

        $fileUtility = $this->getFileUtility(null, $imageService);

        $processedFile = $fileUtility->processCropVariants(
            $file,
            ProcessingConfiguration::fromOptions($options),
            $processedFile
        );

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'alt' => null,
            'type' => 'image',
            'width' => 526,
            'height' => 526,
            'urlTest' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'cropVariants' => [
                'default' => [
                    'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
                    'width' => 526,
                    'height' => 526,
                ],
            ],
        ], $processedFile);

        $options = [
            'legacyReturn' => 0,
            'cacheBusting' => 1,
            'properties.' => ['byType' => 1, 'includeOnly' => 'alternative as alt,type,width,height', 'flatten' => 1],
            'conditionalCropVariant' => 0,
            'autogenerate.' => [
                'urlTest' => ['factor' => 2],
            ],
        ];

        $processedFile = $fileUtility->process($file, ProcessingConfiguration::fromOptions($options));

        $file = $this->getMockFileForData(
            $fileData,
            ['crop' => '{"default":{"cropArea":{"x":1,"y":1,"width":2,"height":2},"selectedRatio":"NaN","focusArea":null},"mobile":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}']
        );

        $processedFileMock = $this->getMockProcessedFileForData($fileData);

        $cropVariantCollection = CropVariantCollection::create((string)$file->getProperty('crop'));
        $cropArea = $cropVariantCollection->getCropArea('default');

        $imageService = $this->getImageServiceWithProcessedFile($file, $processedFileMock, [
            [
                'width' => 0,
                'height' => 0,
                'minWidth' => 0,
                'minHeight' => 0,
                'maxWidth' => 0,
                'maxHeight' => 0,
                'crop' => $cropArea,
                'fileExtension' => null,
            ],
        ]);

        $fileUtility = $this->getFileUtility(null, $imageService);

        $processedFile = $fileUtility->processCropVariants(
            $file,
            ProcessingConfiguration::fromOptions($options),
            $processedFile
        );

        self::assertSame([
            'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'alt' => null,
            'type' => 'image',
            'width' => 526,
            'height' => 526,
            'urlTest' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'cropVariants' => [
                'default' => [
                    'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
                    'width' => 526,
                    'height' => 526,
                ],
                'mobile' => [
                    'url' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
                    'width' => 526,
                    'height' => 526,
                ],
            ],
        ], $processedFile);
    }

    public function testExceptionCatching(): void
    {
        $this->testProcessImageFileException(new UnexpectedValueException('test'));
        $this->testProcessImageFileException(new RuntimeException('test'));
        $this->testProcessImageFileException(new InvalidArgumentException('test'));
    }

    protected function getFileUtility(
        ?ObjectProphecy $normalizedParams = null,
        $imageService = null,
        ?ObjectProphecy $contentObjectRenderer = null,
        ?ObjectProphecy $rendererRegistry = null
    ): FileUtility {
        if ($contentObjectRenderer === null) {
            $contentObjectRenderer = $this->prophesize(ContentObjectRenderer::class);
        }

        if ($imageService === null) {
            $imageService = $this->prophesize(ImageService::class);
        }

        if ($rendererRegistry === null) {
            $rendererRegistry = $this->prophesize(RendererRegistry::class);
        }

        if ($normalizedParams === null) {
            $normalizedParams = $this->prophesize(NormalizedParams::class);
            $normalizedParams->getSiteUrl()->willReturn('https://test-frontend.tld/test-site');
            $normalizedParams->getRequestHost()->willReturn('https://test-frontend.tld');
        }

        $serverRequest = $this->prophesize(ServerRequest::class);
        $serverRequest->getAttribute('normalizedParams')->willReturn($normalizedParams->reveal());

        $contentObjectRenderer->getRequest()->willReturn($serverRequest);

        if ($imageService instanceof ObjectProphecy) {
            $imageService = $imageService->reveal();
        }

        $container = new Container();
        $listenerProvider = new ListenerProvider($container);
        $eventDispatcher = new EventDispatcher($listenerProvider);

        $fileUtility = $this->createPartialMock(FileUtility::class, ['translate']);
        $fileUtility->__construct(
            $contentObjectRenderer->reveal(),
            $rendererRegistry->reveal(),
            $imageService,
            $eventDispatcher,
            new Features()
        );

        $fileUtility->method('translate')->willReturnCallback(static function ($key, $extension) {
            $translated = [
                'fluid' => [
                    'viewhelper.format.bytes.units' => 'B,KB,MB,GB,TB,PB,EB,ZB,YB',
                ],
            ];

            return $translated[$extension][$key] ?? null;
        });

        return $fileUtility;
    }

    protected function getMockFileForData($data, array $overrideToArray = [])
    {
        $file = $this->createPartialMock(
            File::class,
            [
                'getMetaData',
                'getStorage',
                'toArray',
                'getProperty',
                'getUid',
                'getPublicUrl',
            ]
        );
        $resourceStorage = $this->prophesize(ResourceStorage::class);
        $resourceStorage->getFileInfo($file)->willReturn($data);
        $metaData = $this->prophesize(MetaDataAspect::class);
        $metaData->get()->willReturn(
            [
                'width' => $data['width'],
                'height' => $data['height'],
                'crop' => null,
                'minWidth' => null,
                'maxWidth' => null,
                'minHeight' => null,
                'maxHeight' => null,
            ]
        );

        $file->method('getMetaData')->willReturn($metaData->reveal());
        $file->method('getStorage')->willReturn($resourceStorage->reveal());
        $file->method('getUid')->willReturn($data['uid']);
        $file->method('getPublicUrl')->willReturn('/fileadmin/test-file.jpg');
        if ($overrideToArray !== []) {
            $_data = array_merge($data, $overrideToArray);
            $file->method('getProperty')->willReturnCallback(static function ($key) use ($_data) {
                return $_data[$key] ?? null;
            });
            $file->method('toArray')->willReturn($overrideToArray);
        } else {
            $file->method('toArray')->willReturn(
                [
                    'extension' => 'jpg',
                    'title' => null,
                    'alternative' => null,
                    'description' => null,
                ]
            );
        }
        $file->__construct($data, $this->prophesize(ResourceStorage::class)->reveal());
        return $file;
    }

    protected function getMockFileReferenceForData($data, $type = 'image')
    {
        $fileReference = $this->createPartialMock(
            FileReference::class,
            [
                'getPublicUrl',
                'getUid',
                'getProperty',
                'hasProperty',
                'toArray',
                'getType',
                'getMimeType',
                'getProperties',
                'getSize',
                'getExtension',
            ]
        );
        $fileReference->method('getUid')->willReturn(103);
        if ($type === 'video') {
            $fileReference->method('getMimeType')->willReturn('video/youtube');
            $fileReference->method('getType')->willReturn(AbstractFile::FILETYPE_VIDEO);
            $fileReference->method('getPublicUrl')->willReturn('https://www.youtube.com/watch?v=123456789');
            $fileReference->method('getExtension')->willReturn('');
        } else {
            $fileReference->method('getType')->willReturn(AbstractFile::FILETYPE_IMAGE);
            $fileReference->method('getPublicUrl')->willReturn('/fileadmin/test-file.jpg');
            $fileReference->method('getMimeType')->willReturn('image/jpeg');
            $fileReference->method('getExtension')->willReturn('jpg');
        }

        $fileReference->method('getProperty')->willReturnCallback(static function ($key) use ($data) {
            return $data[$key] ?? null;
        });

        $fileReference->method('hasProperty')->willReturnCallback(static function ($key) use ($data) {
            return array_key_exists($key, $data);
        });

        $fileReference->method('toArray')->willReturn($data);
        $fileReference->method('getProperties')->willReturn($data);
        $fileReference->method('getSize')->willReturn($data['size']);
        return $fileReference;
    }

    protected function getMockProcessedFileForData($data)
    {
        $processedFile = $this->createPartialMock(
            ProcessedFile::class,
            ['getProperty', 'getMimeType', 'getSize', 'hasProperty', 'getPublicUrl']
        );
        $processedFile->method('getMimeType')->willReturn('image/jpeg');
        $processedFile->method('getSize')->willReturn($data['size']);
        $processedFile->method('getProperty')->willReturnCallback(static function ($key) use ($data) {
            return $data[$key] ?? null;
        });

        $processedFile->method('hasProperty')->willReturnCallback(static function ($key) use ($data) {
            return array_key_exists($key, $data);
        });

        return $processedFile;
    }

    protected function getImageServiceWithProcessedFile($file, $processedFile, $processingInstruction = [])
    {
        if ($processingInstruction === []) {
            $processingInstruction = [
                'width' => 0,
                'height' => 0,
                'minWidth' => 0,
                'minHeight' => 0,
                'maxWidth' => 0,
                'maxHeight' => 0,
                'crop' => null,
                'fileExtension' => null,
            ];
        }
        $imageService = $this->prophesize(ImageService::class);

        $imageService->getImageUri($processedFile, true)->willReturn(
            'https://test-frontend.tld/fileadmin/test-file.jpg'
        );
        $imageService->applyProcessingInstructions($file, Argument::any())->willReturn($processedFile);

        return $imageService;
    }

    protected function getBaselineResultArrayForFile(): array
    {
        return [
            'publicUrl' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'properties' =>
                [
                    'title' => null,
                    'alternative' => null,
                    'description' => null,
                    'link' => null,
                    'linkData' => null,
                    'mimeType' => 'image/jpeg',
                    'type' => 'image',
                    'filename' => 'test-file.jpg',
                    'originalUrl' => '/fileadmin/test-file.jpg',
                    'uidLocal' => 103,
                    'fileReferenceUid' => null,
                    'size' => '71 KB',
                    'dimensions' =>
                        [
                            'width' => 526,
                            'height' => 526,
                        ],
                    'cropDimensions' =>
                        [
                            'width' => 526,
                            'height' => 526,
                        ],
                    'crop' => null,
                    'autoplay' => null,
                    'extension' => 'jpg',
                ],
        ];
    }

    protected function getBaselineResultArrayForFileReference(): array
    {
        return [
            'publicUrl' => 'https://test-frontend.tld/fileadmin/test-file.jpg?1639061876',
            'properties' =>
                [
                    'title' => null,
                    'alternative' => null,
                    'description' => null,
                    'link' => null,
                    'linkData' => null,
                    'mimeType' => 'image/jpeg',
                    'type' => 'image',
                    'filename' => 'test-file.jpg',
                    'originalUrl' => '/fileadmin/test-file.jpg',
                    'uidLocal' => 103,
                    'fileReferenceUid' => 103,
                    'size' => '71 KB',
                    'dimensions' =>
                        [
                            'width' => 526,
                            'height' => 526,
                        ],
                    'cropDimensions' =>
                        [
                            'width' => 526,
                            'height' => 526,
                        ],
                    'crop' => '{"default":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}',
                    'autoplay' => 0,
                    'extension' => 'jpg',
                ],
        ];
    }

    protected function getBaselineResultArrayForVideoFileReference(): array
    {
        return [
            'publicUrl' => 'https://www.youtube.com/watch?v=123456789',
            'properties' =>
                [
                    'title' => null,
                    'alternative' => null,
                    'description' => null,
                    'link' => null,
                    'linkData' => null,
                    'mimeType' => 'video/youtube',
                    'type' => 'video',
                    'filename' => 'test-file.jpg',
                    'originalUrl' => 'https://www.youtube.com/watch?v=123456789',
                    'uidLocal' => 103,
                    'fileReferenceUid' => 103,
                    'size' => '71 KB',
                    'dimensions' =>
                        [
                            'width' => 526,
                            'height' => 526,
                        ],
                    'cropDimensions' =>
                        [
                            'width' => 526,
                            'height' => 526,
                        ],
                    'crop' => '{"default":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}',
                    'autoplay' => 0,
                    'extension' => 'jpg',
                ],
        ];
    }

    protected function getFileReferenceBaselineData(): array
    {
        return [
            'extension' => 'jpg',
            'size' => 72392,
            'title' => null,
            'description' => null,
            'alternative' => null,
            'name' => 'test-file.jpg',
            'link' => '',
            'crop' => '{"default":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}',
            'autoplay' => 0,
            'minWidth' => null,
            'minHeight' => null,
            'maxWidth' => null,
            'maxHeight' => null,
            'width' => 526,
            'uid_local' => 103,
            'height' => 526,
            'tstamp' => 1639061876,
        ];
    }

    protected function testProcessImageFileException($exception): void
    {
        $fileReferenceData = $this->getFileReferenceBaselineData();
        $fileReference = $this->getMockFileReferenceForData($fileReferenceData, 'video');
        $imageService = $this->prophesize(ImageService::class);

        $imageService->getImageUri(Argument::any())->willReturn(
            'https://returnValue-frontend.tld/fileadmin/returnValue-file.jpg'
        );

        $imageService = $this->createPartialMock(ImageService::class, ['applyProcessingInstructions', 'getImageUri']);
        $imageService->method('getImageUri')->willReturn('');
        $imageService->method('applyProcessingInstructions')->willThrowException($exception);

        $fileUtility = $this->getFileUtility(null, $imageService);

        try {
            $fileUtility->processImageFile($fileReference, ProcessingConfiguration::fromOptions([]));
        } catch (Throwable $throwable) {
            if (!empty($fileUtility->getErrors()['processImageFile'])) {
                $errors = $fileUtility->getErrors()['processImageFile'];
                if (reset($errors) !== get_class($exception)) {
                    self::fail('Different exception triggered: ' . $errors[0]);
                }
            }
        }
    }
}
