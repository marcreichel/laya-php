<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** laya-serve could not be reached (connection refused, DNS, timeout, ...). */
final class TransportException extends \RuntimeException implements LayaException {}
