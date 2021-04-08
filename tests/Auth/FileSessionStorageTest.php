<?php

declare(strict_types=1);

namespace ShopifyTest\Auth;

use Shopify\Auth\Session;
use Shopify\Auth\FileSessionStorage;
use ShopifyTest\BaseTestCase;
use org\bovigo\vfs\vfsStream;

final class FileSessionStorageTest extends BaseTestCase
{
    private string $sessionId = 'test_session';
    private Session $session;

    public function setUp(): void
    {
        $this->session = new Session($this->sessionId);
        $this->session->setShop('test-shop.myshopify.io');
        $this->session->setState('1234');
        $this->session->setScope('read_products');
        $this->session->setExpires(strtotime('+1 day'));
        $this->session->setIsOnline(true);
        $this->session->setAccessToken('totally_real_access_token');

    }

    public function testStoreLoadDeleteSession()
    {
        $root = vfsStream::setup('sessions');
        $storage = new FileSessionStorage(vfsStream::url('sessions'));
        $this->assertTrue($storage->storeSession($this->session));
        $this->assertTrue($root->hasChild('test_session'));

        $this->assertEquals($this->session, $storage->loadSession($this->sessionId));

        $this->assertTrue($storage->deleteSession($this->sessionId));
        $this->assertFalse($root->hasChild('test_session'));
    }

    public function testCreateNewPath()
    {
        $root = vfsStream::setup('sessions');
        $this->assertFalse($root->hasChild('subdirectory'));
        new FileSessionStorage(vfsStream::url('sessions/subdirectory'));
        $this->assertTrue($root->hasChild('subdirectory'));
    }

    public function testLoadNonexistentSession()
    {
        $storage = new FileSessionStorage(vfsStream::url('sessions/subdirectory'));
        $this->assertEquals(null, $storage->loadSession($this->sessionId));
    }
}