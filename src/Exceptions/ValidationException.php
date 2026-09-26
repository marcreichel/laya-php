<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** 400/413/422: laya rejected the request. The message names the question and what to fix. */
final class ValidationException extends ApiException {}
