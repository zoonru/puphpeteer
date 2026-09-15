<?php

// @generated aliases by tools/upstream/update.cjs. Do not edit.

declare(strict_types=1);
use Nesk\Puphpeteer\Accessibility;
use Nesk\Puphpeteer\Browser;
use Nesk\Puphpeteer\BrowserContext;
use Nesk\Puphpeteer\CDPSession;
use Nesk\Puphpeteer\Connection;
use Nesk\Puphpeteer\ConsoleMessage;
use Nesk\Puphpeteer\Coverage;
use Nesk\Puphpeteer\Dialog;
use Nesk\Puphpeteer\ElementHandle;
use Nesk\Puphpeteer\FileChooser;
use Nesk\Puphpeteer\Frame;
use Nesk\Puphpeteer\HTTPRequest;
use Nesk\Puphpeteer\HTTPResponse;
use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JSHandle;
use Nesk\Puphpeteer\Keyboard;
use Nesk\Puphpeteer\Locator;
use Nesk\Puphpeteer\Mouse;
use Nesk\Puphpeteer\Page;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\SecurityDetails;
use Nesk\Puphpeteer\Target;
use Nesk\Puphpeteer\Touchscreen;
use Nesk\Puphpeteer\Tracing;
use Nesk\Puphpeteer\WebWorker;

if (!class_exists(Accessibility::class)) {
    class_alias(Puppeteer\Accessibility::class, Accessibility::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Accessibility::class)) {
    class_alias(Puppeteer\Accessibility::class, Nesk\Puphpeteer\Resources\Accessibility::class);
}

if (!class_exists(Browser::class)) {
    class_alias(Puppeteer\Browser::class, Browser::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Browser::class)) {
    class_alias(Puppeteer\Browser::class, Nesk\Puphpeteer\Resources\Browser::class);
}

if (!class_exists(BrowserContext::class)) {
    class_alias(Puppeteer\BrowserContext::class, BrowserContext::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\BrowserContext::class)) {
    class_alias(Puppeteer\BrowserContext::class, Nesk\Puphpeteer\Resources\BrowserContext::class);
}

if (!class_exists(CDPSession::class)) {
    class_alias(Puppeteer\CDPSession::class, CDPSession::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\CDPSession::class)) {
    class_alias(Puppeteer\CDPSession::class, Nesk\Puphpeteer\Resources\CDPSession::class);
}

if (!class_exists(Connection::class)) {
    class_alias(Puppeteer\Connection::class, Connection::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Connection::class)) {
    class_alias(Puppeteer\Connection::class, Nesk\Puphpeteer\Resources\Connection::class);
}

if (!class_exists(ConsoleMessage::class)) {
    class_alias(Puppeteer\ConsoleMessage::class, ConsoleMessage::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\ConsoleMessage::class)) {
    class_alias(Puppeteer\ConsoleMessage::class, Nesk\Puphpeteer\Resources\ConsoleMessage::class);
}

if (!class_exists(Coverage::class)) {
    class_alias(Puppeteer\Coverage::class, Coverage::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Coverage::class)) {
    class_alias(Puppeteer\Coverage::class, Nesk\Puphpeteer\Resources\Coverage::class);
}

if (!class_exists(Dialog::class)) {
    class_alias(Puppeteer\Dialog::class, Dialog::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Dialog::class)) {
    class_alias(Puppeteer\Dialog::class, Nesk\Puphpeteer\Resources\Dialog::class);
}

if (!class_exists(ElementHandle::class)) {
    class_alias(Puppeteer\ElementHandle::class, ElementHandle::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\ElementHandle::class)) {
    class_alias(Puppeteer\ElementHandle::class, Nesk\Puphpeteer\Resources\ElementHandle::class);
}

if (!class_exists(FileChooser::class)) {
    class_alias(Puppeteer\FileChooser::class, FileChooser::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\FileChooser::class)) {
    class_alias(Puppeteer\FileChooser::class, Nesk\Puphpeteer\Resources\FileChooser::class);
}

if (!class_exists(Frame::class)) {
    class_alias(Puppeteer\Frame::class, Frame::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Frame::class)) {
    class_alias(Puppeteer\Frame::class, Nesk\Puphpeteer\Resources\Frame::class);
}

if (!class_exists(HTTPRequest::class)) {
    class_alias(Puppeteer\HTTPRequest::class, HTTPRequest::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\HTTPRequest::class)) {
    class_alias(Puppeteer\HTTPRequest::class, Nesk\Puphpeteer\Resources\HTTPRequest::class);
}

if (!class_exists(HTTPResponse::class)) {
    class_alias(Puppeteer\HTTPResponse::class, HTTPResponse::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\HTTPResponse::class)) {
    class_alias(Puppeteer\HTTPResponse::class, Nesk\Puphpeteer\Resources\HTTPResponse::class);
}

if (!class_exists(JSHandle::class)) {
    class_alias(Puppeteer\JSHandle::class, JSHandle::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\JSHandle::class)) {
    class_alias(Puppeteer\JSHandle::class, Nesk\Puphpeteer\Resources\JSHandle::class);
}

if (!class_exists(Keyboard::class)) {
    class_alias(Puppeteer\Keyboard::class, Keyboard::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Keyboard::class)) {
    class_alias(Puppeteer\Keyboard::class, Nesk\Puphpeteer\Resources\Keyboard::class);
}

if (!class_exists(Locator::class)) {
    class_alias(Puppeteer\Locator::class, Locator::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Locator::class)) {
    class_alias(Puppeteer\Locator::class, Nesk\Puphpeteer\Resources\Locator::class);
}

if (!class_exists(Mouse::class)) {
    class_alias(Puppeteer\Mouse::class, Mouse::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Mouse::class)) {
    class_alias(Puppeteer\Mouse::class, Nesk\Puphpeteer\Resources\Mouse::class);
}

if (!class_exists(Page::class)) {
    class_alias(Puppeteer\Page::class, Page::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Page::class)) {
    class_alias(Puppeteer\Page::class, Nesk\Puphpeteer\Resources\Page::class);
}

if (!class_exists(SecurityDetails::class)) {
    class_alias(Puppeteer\SecurityDetails::class, SecurityDetails::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\SecurityDetails::class)) {
    class_alias(Puppeteer\SecurityDetails::class, Nesk\Puphpeteer\Resources\SecurityDetails::class);
}

if (!class_exists(Target::class)) {
    class_alias(Puppeteer\Target::class, Target::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Target::class)) {
    class_alias(Puppeteer\Target::class, Nesk\Puphpeteer\Resources\Target::class);
}

if (!class_exists(Touchscreen::class)) {
    class_alias(Puppeteer\Touchscreen::class, Touchscreen::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Touchscreen::class)) {
    class_alias(Puppeteer\Touchscreen::class, Nesk\Puphpeteer\Resources\Touchscreen::class);
}

if (!class_exists(Tracing::class)) {
    class_alias(Puppeteer\Tracing::class, Tracing::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\Tracing::class)) {
    class_alias(Puppeteer\Tracing::class, Nesk\Puphpeteer\Resources\Tracing::class);
}

if (!class_exists(WebWorker::class)) {
    class_alias(Puppeteer\WebWorker::class, WebWorker::class);
}

if (!class_exists(Nesk\Puphpeteer\Resources\WebWorker::class)) {
    class_alias(Puppeteer\WebWorker::class, Nesk\Puphpeteer\Resources\WebWorker::class);
}

if (!class_exists(Puppeteer::class)) {
    class_alias(Puppeteer\Puppeteer::class, Puppeteer::class);
}

if (!class_exists(Nesk\Rialto\Data\JsFunction::class)) {
    class_alias(JsFunction::class, Nesk\Rialto\Data\JsFunction::class);
}
