<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Attributes;

/**
 * Marks a backed enum's cases as ordered levels, lowest first. A decision class parameter of this type
 * becomes a score question, and its value is the case at the most likely level.
 *
 *     #[Scale]
 *     enum Urgency: int
 *     {
 *         #[Describe('can wait a week or more')]
 *         case NotUrgent = 0;
 *         case Soon = 1;
 *         case Blocking = 2;
 *     }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Scale {}
