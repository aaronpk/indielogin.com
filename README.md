IndieLogin
==========

[![Deploy](https://www.herokucdn.com/deploy/button.svg)](https://heroku.com/deploy)

IndieLogin enables users to sign in with their domain name by linking their domain name to existing authentication providers.

## Running in docker

To run this locally in a docker container you will need a modern version of docker-compose and docker supporting docker-compose.yml version 3.5

### Starting docker locally

#### With output

`docker-compose up`

#### Without output

`docker-compose up -d`

### Stopping docker

`ctrl+d` will stop docker if using with output

### cleaning docker state

This is an initial effort. Improvements accepted

```
docker-compose down --remove-orphans
docker rmi indielogincom_app:latest
sudo rm -rf data/{redis,mysql}/*
```

### Attaching to running container

`docker exec -it indielogincom_app_1 sh`
