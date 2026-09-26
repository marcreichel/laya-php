<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** 503: laya-serve is at its concurrency limit. Safe to retry after a short wait. */
final class ServerBusyException extends ServerException {}
