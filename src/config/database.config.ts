export const databaseConfig = {
  url: process.env.DATABASE_URL || 
    `postgresql://${process.env.DATABASE_USER}:${process.env.DATABASE_PASSWORD}@${process.env.DATABASE_HOST}:${process.env.DATABASE_PORT}/${process.env.DATABASE_NAME}?schema=public`,
};

