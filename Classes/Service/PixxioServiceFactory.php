<?php

namespace Flownative\Pixxio\Service;

/*
 * This file is part of the Flownative.Pixxio package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 * (c) pixx.io GmbH - pixx.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Utility\Environment;

/**
 * Factory for the Pixx.io service class
 *
 * @Flow\Scope("singleton")
 */
class PixxioServiceFactory
{
    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @var array
     */
    private $fields = [
        'id', 'originalFilename', 'fileType', 'keywords', 'createDate', 'imageHeight', 'imageWidth', 'originalPath',
        'subject', 'description', 'modifyDate', 'fileSize', 'modifiedImagePaths', 'imagePath'
    ];

    /**
     * @var array
     */
    private $imageOptions = [
        [
            'width' => 400,
            'height' => 400,
            'quality' => 90
        ],
        [
            'width' => 1500,
            'height' => 1500,
            'quality' => 90
        ],
        [
            'sizeMax' => 1920,
            'quality' => 90
        ]
    ];

    /**
     * Creates a new PixxioClient instance and authenticates against the Pixx.io API
     *
     * @param string $accountIdentifier
     * @param string $apiEndpointUri
     * @param string $apiKey
     * @return PixxioClient
     */
    public function createForAccount(string $accountIdentifier, string $apiEndpointUri, string $apiKey)
    {
        $client = new PixxioClient(
            $apiEndpointUri,
            $apiKey,
            $this->fields,
            $this->imageOptions
        );
        return $client;
    }
}
