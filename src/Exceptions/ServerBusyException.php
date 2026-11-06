<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** 503: laya-serve is at its concurrency limit, or 408: it didn't receive the request body in time. Safe to retry after a short wait. */
final class ServerBusyException extends ServerException {}
