## Before lab instalation:
  - You need to make sure docker is installed on your workstation. Please follow the instructions from the URL below for installing docker before you start lab instalation:

    https://docs.docker.com/compose/install/

## Lab instalation:
**Step1:**
  - Stop your apache server by running the following command:
    ```sh
    sudo service apache2 stop
    ```
**Step2:**
  - Get into the framework directory:
    ```sh
    cd /your/framework/directory
    ```
  - Start docker by running the following command:
    ```sh
    docker-compose up -d
    ```
  - Now you should be able to access the project through:
    http://localhost

**Step4:**
  - After your work is done you can stop docker by running the following command from your project directory:
    ```sh
    docker-compose down
    ```

**For composer update:""
  - To update composer:
  ```sh
  docker exec jambura-core-composer-1 composer update
  ```
