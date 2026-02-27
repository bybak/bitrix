FROM mcr.microsoft.com/playwright:v1.58.2-jammy

WORKDIR /work/tools/playwright

COPY package.json package-lock.json* ./
RUN npm install

COPY . .

