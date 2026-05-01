FROM mcr.microsoft.com/playwright:v1.59.1-jammy

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --omit=dev

COPY tsconfig.json ./
COPY src ./src

RUN npm install --include=dev typescript@5.7.3 \
 && npx tsc -p tsconfig.json \
 && npm prune --omit=dev

CMD ["node", "dist/index.js"]
