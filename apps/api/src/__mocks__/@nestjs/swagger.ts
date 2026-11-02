// Jest ESM stub — Swagger decorators are metadata-only; stubbing them
// prevents @nestjs/swagger's CJS require() from breaking the ESM test runner.
const noop = () => () => {};
export const ApiTags = noop;
export const ApiOperation = noop;
export const ApiQuery = noop;
export const ApiBearerAuth = noop;
export const ApiBody = noop;
export const ApiParam = noop;
export const ApiResponse = noop;
export const ApiProperty = noop;
export const ApiPropertyOptional = noop;
export const ApiExtraModels = noop;
export const DocumentBuilder = class {
  setTitle() { return this; }
  setDescription() { return this; }
  setVersion() { return this; }
  addBearerAuth() { return this; }
  build() { return {}; }
};
export const SwaggerModule = {
  createDocument: () => ({}),
  setup: () => {},
};
