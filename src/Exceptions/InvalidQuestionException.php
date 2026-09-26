<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** A question or decision class is malformed. Thrown before any request is sent. */
final class InvalidQuestionException extends \InvalidArgumentException implements LayaException {}
