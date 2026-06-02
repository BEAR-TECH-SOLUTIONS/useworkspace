<?php

/**
 * Self-hosted-only routes. Loaded by EditionServiceProvider when
 * TC_EDITION=self_hosted. The license guard + phone-home loop run
 * via middleware + console commands, not HTTP routes, so this file
 * is intentionally empty today — but the file exists so the loader
 * has a stable handle and future on-prem-only admin endpoints
 * (instance health, local user impersonation, etc.) have a home.
 */
