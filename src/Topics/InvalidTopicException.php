<?php

namespace Abdalla\ReportGenerator\Topics;

use LogicException;

/** A topic declaration that cannot work: thrown while topics are loaded, so mistakes show up at once. */
final class InvalidTopicException extends LogicException
{
}
