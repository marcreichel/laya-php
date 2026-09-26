<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** 401: the API key is missing or wrong. */
final class AuthenticationException extends ApiException {}
