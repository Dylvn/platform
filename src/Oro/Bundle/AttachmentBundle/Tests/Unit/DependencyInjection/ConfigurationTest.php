<?php

namespace Oro\Bundle\AttachmentBundle\Tests\Unit\DependencyInjection;

use Oro\Bundle\AttachmentBundle\DependencyInjection\Configuration;
use Oro\Bundle\AttachmentBundle\Tools\WebpConfiguration;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends \PHPUnit\Framework\TestCase
{
    private const SYSTEM_CONFIG = [
        'settings' => [
            'png_quality' => [
                'value' => 100,
                'scope' => 'app',
            ],
            'jpeg_quality' => [
                'value' => 85,
                'scope' => 'app',
            ],
            'maxsize' => [
                'value' => 10,
                'scope' => 'app',
            ],
            'upload_file_mime_types' => [
                'value' => '',
                'scope' => 'app',
            ],
            'upload_image_mime_types' => [
                'value' => '',
                'scope' => 'app',
            ],
            'processors_allowed' => [
                'value' => true,
                'scope' => 'app',
            ],
            'webp_quality' => [
                'value' => 85,
                'scope' => 'app',
            ],
            'resolved' => true,
        ],
    ];

    public function testGetConfigTreeBuilder(): void
    {
        $configuration = new Configuration();
        $builder = $configuration->getConfigTreeBuilder();
        $this->assertInstanceOf(TreeBuilder::class, $builder);

        $root = $builder->buildTree();
        $this->assertInstanceOf(ArrayNode::class, $root);
        $this->assertEquals('oro_attachment', $root->getName());
    }

    public function testProcessConfigurationWhenDefault(): void
    {
        $processor = new Processor();

        $expected = self::SYSTEM_CONFIG + [
                'debug_images' => true,
                'maxsize' => 10,
                'upload_file_mime_types' => [],
                'upload_image_mime_types' => [],
                'png_quality' => 100,
                'jpeg_quality' => 85,
                'processors_allowed' => true,
                'webp_strategy' => WebpConfiguration::ENABLED_IF_SUPPORTED,
            ];

        self::assertEquals($expected, $processor->processConfiguration(new Configuration(), []));
    }

    /**
     * @dataProvider webpStrategyDataProvider
     *
     * @param string $webpStrategy
     */
    public function testProcessConfigurationWhenWebpStrategy(string $webpStrategy): void
    {
        $processor = new Processor();

        $config = $processor->processConfiguration(
            new Configuration(),
            ['oro_attachment' => ['webp_strategy' => $webpStrategy]]
        );
        self::assertEquals($webpStrategy, $config['webp_strategy']);
    }

    public function webpStrategyDataProvider(): array
    {
        return [
            [WebpConfiguration::ENABLED_FOR_ALL],
            [WebpConfiguration::ENABLED_IF_SUPPORTED],
            [WebpConfiguration::DISABLED],
        ];
    }

    public function testProcessConfigurationWhenInvalidWebpStrategy(): void
    {
        $processor = new Processor();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectErrorMessage('The value "invalid" is not allowed for path "oro_attachment.webp_strategy".');

        $processor->processConfiguration(
            new Configuration(),
            ['oro_attachment' => ['webp_strategy' => 'invalid']]
        );
    }
}
