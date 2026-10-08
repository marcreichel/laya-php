<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** A request option is malformed, e.g. a minConfidence laya-serve would refuse. Thrown before any request is sent. */
final class InvalidOptionException extends \InvalidArgumentException implements LayaException {}
