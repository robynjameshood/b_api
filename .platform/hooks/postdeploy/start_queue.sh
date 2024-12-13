#!/bin/bash

# Enable the workers
systemctl enable laravel_worker.service

# Restart the workers
systemctl restart laravel_worker.service
