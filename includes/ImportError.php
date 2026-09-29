<?php
namespace ZoerConnect;

/** Plugin-authored import failure; its message is safe to show to clients. */
class ImportError extends \RuntimeException {}

/** A staged or live table no longer matches its staging fingerprint. Nothing live
 * has been replaced when this is raised before activation. */
final class StageChanged extends ImportError {}
