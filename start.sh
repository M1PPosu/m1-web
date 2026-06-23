#!/bin/sh

#set -eu

docker network create osuweb_external
docker compose up --build
