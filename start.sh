#!/bin/sh

set -eu

exec docker compose up -d --build
