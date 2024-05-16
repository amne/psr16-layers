<?php

namespace Amne\Tests\LayerCache;

use Amne\Psr16Layers\LayerCache;
use MatthiasMullie\Scrapbook\Adapters\MemoryStore;
use MatthiasMullie\Scrapbook\Psr16\SimpleCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class LayerCacheTest extends TestCase 
{
	protected LayerCache $subject;

	/** @var CacheInterface[] */
	protected array $layers;

	public function setUp(): void
	{
		$this->layers = [
			new SimpleCache(new MemoryStore()),
			new SimpleCache(new MemoryStore()),
		];

		$this->subject = new LayerCache($this->layers, [2,10]);
	}

	public function testGetNonExistingAndDefault()
	{
		// key not found returns false
		$this->assertNull($this->subject->get('does-not-exist'));

		// key not found returns default value
		$this->assertEquals('default-1', $this->subject->get('does-not-exist','default-1'));
	}

	public function testGetAndSet()
	{
		// existing key is retrieved
		$this->layers[1]->set('key-1', 'value-1');
		$this->assertEquals('value-1', $this->subject->get('key-1'));

		// key is set in all layers
		$this->subject->set('key-2', 'value-2');
		$this->assertEquals('value-2', $this->layers[0]->get('key-2'));
		$this->assertEquals('value-2', $this->layers[1]->get('key-2'));

		// set overrides existing keys in higher layers
		$this->layers[0]->set('key-3', 'value-3-throwaway');
		$this->subject->set('key-3', 'value-3');
		$this->assertEquals('value-3', $this->layers[0]->get('key-3'));
	}

	public function testGetMultiple()
	{
		// returns null for multiple keys
		$expected = ['does-not-exist-1' => null, 'does-not-exist-2' => null];
		$this->assertSame($expected, $this->subject->getMultiple(array_keys($expected)));

		// returns null for non existing keys
		$expected = ['key-1' => 'value-1', 'does-not-exist-1' => null];
		$this->subject->set('key-1', 'value-1');
		$this->assertSame($expected, $this->subject->getMultiple(array_keys($expected)));

		// syncs back missing keys found in lower layers
		$expected = ['key-1' => 'value-1', 'does-not-exist-1' => null];
		$this->layers[1]->set('key-1', 'value-1');
		$this->assertSame($expected, $this->subject->getMultiple(array_keys($expected)));
		$this->assertEquals('value-1', $this->layers[0]->get('key-1'));
	}
}
