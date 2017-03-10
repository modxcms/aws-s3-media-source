# Amazon S3 Media Source for MODX Revolution

Using 3.* https://aws.amazon.com/sdk-for-php/ & https://github.com/aws/aws-sdk-php

What are Media Sources? https://docs.modx.com/revolution/2.x/administering-your-site/media-sources

## Features
* CRUD (create, read, update and delete) for files and folders
* Rename files and folders, #7
* Move/Drag files and folders within the same Media Source, #7
* Limit an S3 Media Source to sub directory/folder of a given Bucket. #5
* Works with static sites with custom domains, example: assets.example.com

## Set up
Download the latest release and install via Package Manager

1. Then in the MODX Manager go to Media -> Media Sources -> Create New Media Source
2. Fill in the Required options
   - **url** The URL to the Amazon S3 instance. Often http://myaccount.s3.amazonaws.com/, 
   https://s3.amazonaws.com/myaccount/ or if you have set up a custom domain https://assets.example.com/. Make sure the url ends with a slash.
   - **bucket** The bucket to connect the source to. About buckets: http://docs.aws.amazon.com/AmazonS3/latest/dev/UsingBucket.html
   - **key** The Amazon key used for authentication to the bucket. Find your key: https://aws.amazon.com/developers/access-keys/
   - **secret_key** The Amazon secret key for authentication to the bucket.
   - **region** like us-west-1 Find your region: http://docs.aws.amazon.com/general/latest/gr/rande.html#s3_region
3. Save
