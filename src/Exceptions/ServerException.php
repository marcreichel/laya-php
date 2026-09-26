<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** 5xx, any other unexpected status, or a response that is not laya-shaped. */
class ServerException extends ApiException {}
