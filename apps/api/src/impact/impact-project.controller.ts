import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { ImpactProjectService } from './impact-project.service';

@ApiTags('impact-projects')
@Controller('impact-projects')
export class ImpactProjectController {
  constructor(private readonly impactProjectService: ImpactProjectService) {}

  @Get('stats')
  @ApiOperation({ summary: 'Get impact project stats' })
  stats() {
    return this.impactProjectService.getStats();
  }

  @Get()
  @ApiOperation({ summary: 'List impact projects (public)' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.impactProjectService.listProjects(page, perPage);
  }

  @Get(':impactProject')
  @ApiOperation({ summary: 'Get impact project by ID or slug' })
  getOne(@Param('impactProject') impactProject: string) {
    return this.impactProjectService.getProject(impactProject);
  }
}
