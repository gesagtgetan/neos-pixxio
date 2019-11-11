<?php

namespace Flownative\Pixxio\AssetSource;

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

use Flownative\Pixxio\Domain\Model\ClientSecret;
use Flownative\Pixxio\Domain\Repository\ClientSecretRepository;
use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\MissingClientSecretException;
use Flownative\Pixxio\Service\PixxioClient;
use Flownative\Pixxio\Service\PixxioServiceFactory;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Flow\Security\Context;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Utility\MediaTypes;

/**
 *
 */
class PixxioAssetSource implements AssetSourceInterface
{
    /**
     * @var string
     */
    private $assetSourceIdentifier;

    /**
     * @var PixxioAssetProxyRepository
     */
    private $assetProxyRepository;

    /**
     * @Flow\Inject
     * @var PixxioServiceFactory
     */
    protected $pixxioServiceFactory;

    /**
     * @Flow\Inject
     * @var ClientSecretRepository
     */
    protected $clientSecretRepository;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @var string
     */
    private $apiEndpointUri;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $sharedRefreshToken;

    /**
     * @var PixxioClient
     */
    private $pixxioClient;

    /**
     * @var array
     */
    private $assetSourceOptions;

    /**
     * @param string $assetSourceIdentifier
     * @param array $assetSourceOptions
     */
    public function __construct(string $assetSourceIdentifier, array $assetSourceOptions)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}[a-z]$/', $assetSourceIdentifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid asset source identifier "%s". The identifier must match /^[a-z][a-z0-9-]{0,62}[a-z]$/', $assetSourceIdentifier), 1525790890);
        }

        $this->assetSourceIdentifier = $assetSourceIdentifier;
        $this->assetSourceOptions = $assetSourceOptions;

        foreach ($assetSourceOptions as $optionName => $optionValue) {
            switch ($optionName) {
                case 'apiEndpointUri':
                    $uri = new Uri($optionValue);
                    $this->apiEndpointUri = $uri->__toString();
                break;
                case 'apiKey':
                    if (!is_string($optionValue) || empty($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid api key specified for Pixx.io asset source %s', $assetSourceIdentifier), 1525792639);
                    }
                    $this->apiKey = $optionValue;
                break;
                case 'sharedRefreshToken':
                    if (!is_string($optionValue) || empty($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid shared refresh token specified for Pixx.io asset source %s', $assetSourceIdentifier), 1528806843);
                    }
                    $this->sharedRefreshToken = $optionValue;
                break;
                case 'mediaTypes':
                    if (!is_array($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid media types specified for Pixx.io asset source %s', $assetSourceIdentifier), 1542809628);
                    }
                    foreach ($optionValue as $mediaType => $mediaTypeOptions) {
                        if (MediaTypes::getFilenameExtensionsFromMediaType($mediaType) === []) {
                            throw new \InvalidArgumentException(sprintf('Unknown media type "%s" specified for Pixx.io asset source %s', $mediaType, $assetSourceIdentifier), 1542809775);
                        }
                    }
                break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown asset source option "%s" specified for Pixx.io asset source "%s". Please check your settings.', $optionName, $assetSourceIdentifier), 1525790910);
            }
        }
    }

    /**
     * @param string $assetSourceIdentifier
     * @param array $assetSourceOptions
     * @return AssetSourceInterface
     */
    public static function createFromConfiguration(string $assetSourceIdentifier, array $assetSourceOptions): AssetSourceInterface
    {
        return new static($assetSourceIdentifier, $assetSourceOptions);
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->assetSourceIdentifier;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return 'pixx.io';
    }

    /**
     * @return AssetProxyRepositoryInterface
     */
    public function getAssetProxyRepository(): AssetProxyRepositoryInterface
    {
        if ($this->assetProxyRepository === null) {
            $this->assetProxyRepository = new PixxioAssetProxyRepository($this);
        }

        return $this->assetProxyRepository;
    }

    /**
     * @return bool
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * @return array
     */
    public function getAssetSourceOptions(): array
    {
        return $this->assetSourceOptions;
    }

    /**
     * @return PixxioClient
     * @throws MissingClientSecretException
     * @throws AuthenticationFailedException
     */
    public function getPixxioClient(): PixxioClient
    {
        if ($this->pixxioClient === null) {
            if ($this->securityContext->isInitialized()) {
                $accountIdentifier = $this->securityContext->getAccount()->getAccountIdentifier();
                $clientSecret = $this->clientSecretRepository->findOneByFlowAccountIdentifier($accountIdentifier);
            } else {
                $accountIdentifier = 'shared';
                $clientSecret = null;
            }

            $isInvalidSecret = $clientSecret === null || $clientSecret->getRefreshToken() === '';
            if ($isInvalidSecret && !empty($this->sharedRefreshToken)) {
                $clientSecret = new ClientSecret();
                $clientSecret->setRefreshToken($this->sharedRefreshToken);
                $clientSecret->setFlowAccountIdentifier($accountIdentifier);
            }

            if ($clientSecret === null || $clientSecret->getRefreshToken() === '') {
                throw new MissingClientSecretException(
                    sprintf(
                        'No client secret found for account %s. ' .
                        'Please set up the pixx.io plugin with the correct credentials.',
                        $accountIdentifier
                    ),
                    1526544548
                );
            }

            $this->pixxioClient = $this->pixxioServiceFactory->createForAccount(
                $accountIdentifier,
                $this->apiEndpointUri,
                $this->apiKey
            );

            $this->pixxioClient->authenticate($clientSecret->getRefreshToken());
        }
        return $this->pixxioClient;
    }
}
