import {
  Controller, Get, Post, Patch, Param, Query, Body, UseGuards, HttpCode,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
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

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create impact project' })
  store(@Body() body: { title: string; slug: string; sdg_number: number; funding_target_cents: number; currency?: string; description?: string; starts_on?: string; ends_on?: string }) {
    return this.impactProjectService.createProject(body);
  }

  @Patch(':impactProject')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Update impact project' })
  update(@Param('impactProject') impactProject: string, @Body() body: { title?: string; description?: string; status?: string; ends_on?: string }) {
    return this.impactProjectService.updateProject(impactProject, body);
  }

  @Get(':impactProject/evidence')
  @ApiOperation({ summary: 'List evidence for impact project' })
  listEvidence(@Param('impactProject') _impactProject: string) { return { data: [] }; }

  @Post(':impactProject/evidence')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Attach evidence to impact project' })
  createEvidence(@Param('impactProject') _impactProject: string, @Body() _body: Record<string, unknown>) { return { id: 1, created_at: new Date().toISOString() }; }
}
