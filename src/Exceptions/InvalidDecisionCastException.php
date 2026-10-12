<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** An Eloquent attribute cast with AsDecision was given, or read back, something that isn't its decision class. */
final class InvalidDecisionCastException extends \UnexpectedValueException implements LayaException {}
