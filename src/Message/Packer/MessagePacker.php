<?php

namespace Consilience\Iso8583\Message\Packer;

use Consilience\Iso8583\Cache\CacheManager;
use Consilience\Iso8583\Message\AbstractPackUnpack;
use Consilience\Iso8583\Message\Schema\SchemaManager;

/**
 * Class MessagePacker
 *
 * @package Consilience\Iso8583\Message\Packer
 */
class MessagePacker extends AbstractPackUnpack
{

    /** @var SchemaManager $schemaManager the message schema manager */
    protected $schemaManager;

    /** @var CacheManager $cacheManager the schema cache manager */
    protected $cacheManager;

    /**
     * MessagePacker constructor.
     *
     * @param CacheManager  $cacheManager  the schema cache manager
     * @param SchemaManager $schemaManager the schema manager class
     */
    public function __construct(CacheManager $cacheManager, SchemaManager $schemaManager)
    {
        $this->cacheManager  = $cacheManager;
        $this->schemaManager = $schemaManager;
    }

    /**
     * Generates the packed message
     *
     * @return string the packed message
     */
    public function generate(): string
    {
        $message = $this->parseMti() .
            $this->parseBitmap($this->schemaManager->getSetFields()) .
            $this->parseDataElement($this->schemaManager->getSetFields());

        return $this->parseMessageLengthHeader($message) . $message;
    }

    /**
     * Parses the message length header
     *
     * @param string $message the packed message
     *
     * @return string the message length header
     */
    protected function parseMessageLengthHeader(string $message): string
    {
        if ($this->getHeaderLength() > 0) {
            return (string) str_pad(
                dechex((strlen($message) / 2) + $this->getHeaderLength()),
                ($this->getHeaderLength() * 2),
                0,
                STR_PAD_LEFT
            );
        }

        return '';
    }

    /**
     * Parses the message type indicator
     *
     * @return string the parsed MTI
     */
    protected function parseMti(): string
    {
        return str_pad(bin2hex($this->getMti()), 8, 0, STR_PAD_LEFT);
    }

    /**
     * Parses the message bitmap
     *
     * @param array $setFields set fields on the schema
     *
     * @return string the parsed bitmap
     */
    protected function parseBitmap(array $setFields): string
    {
        $bitmap       = '';
        $binaryBitmap = str_repeat(0, 64);

        $presentBitmaps = [
            'primary'   => true,
            'secondary' => false,
            'tertiary'  => false,
        ];

        foreach ($setFields as $field) {
            $bit = $this->cacheManager->getSchemaCache(
                $this->schemaManager->getSchema()
            )->getDataForProperty($field)->getBit();

            if ($bit > 64) {
                if (!$presentBitmaps['secondary']) {
                    $binaryBitmap .= str_repeat(0, 64);
                }

                $binaryBitmap[0] = 1;

                $presentBitmaps['secondary'] = true;
            }

            if ($bit > 128) {
                if (!$presentBitmaps['tertiary']) {
                    $binaryBitmap .= str_repeat(0, 64);
                }

                $binaryBitmap[64] = 1;

                $presentBitmaps['tertiary'] = true;
            }

            $binaryBitmap[($bit - 1)] = 1;
        }

        $bitmapLength = strlen($binaryBitmap);
        for ($i = 0; $i < $bitmapLength; $i += 4) {
            $bitmap .= sprintf('%01x', base_convert(substr($binaryBitmap, $i, 4), 2, 10));
        }

        return $bitmap;
    }

    /**
     * Parses the data element
     *
     * @param array $setFields set fields on the schema
     *
     * @return string the parsed data element
     */
    protected function parseDataElement(array $setFields): string
    {
        $schemaCache = $this->cacheManager->getSchemaCache($this->schemaManager->getSchema());
        $dataCache   = [];

        foreach ($setFields as $field) {
            $fieldData = $schemaCache->getDataForProperty($field);

            $dataCache[$fieldData->getBit()] = $fieldData->getMapper()->pack(
                $this->schemaManager->{$fieldData->getGetterName()}()
            );
        }

        ksort($dataCache);

        return implode("", $dataCache);
    }
}
